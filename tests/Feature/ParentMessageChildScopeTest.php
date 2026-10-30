<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessageReceipt;
use App\Models\Guardian;
use App\Models\Message;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\NotificationDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class ParentMessageChildScopeTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $a;

    private array $b;

    private array $outside;

    private User $parent;

    private Guardian $guardian;

    private Teacher $sharedTeacher;

    private Teacher $onlyA;

    private Teacher $onlyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'fcm.enabled' => false,
            'services.web_push.public_key' => null,
            'services.web_push.private_key' => null,
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
            'queue.batching.database' => config('database.default'),
        ]);
        foreach (['a' => 'أحمد التجريبي', 'b' => 'ليلى التجريبية', 'outside' => 'طالب أسرة أخرى'] as $key => $name) {
            $this->{$key} = $this->createStudentFixture('CHILD-MESSAGES-'.$key);
            $this->{$key}['user']->update(['name' => $name]);
            $this->{$key}['classroom']->update([
                'name' => 'الصف الأول', 'section' => strtoupper($key),
                'academic_year_id' => $this->a['classroom']->academic_year_id,
            ]);
        }
        $subject = $this->createSubjectFor($this->a['classroom'], 'CHILD-MESSAGES');
        $subject->classrooms()->attach($this->b['classroom']);
        $this->sharedTeacher = $this->teacher('المعلمة المشتركة لأحمد وليلى', [$this->a['classroom']->id, $this->b['classroom']->id], $subject->id);
        $this->onlyA = $this->teacher('معلمة أحمد فقط', [$this->a['classroom']->id], $subject->id);
        $this->onlyB = $this->teacher('معلم ليلى فقط', [$this->b['classroom']->id], $subject->id);
        $this->parent = User::factory()->create(['name' => 'ولي أحمد وليلى', 'role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $this->guardian->students()->attach([$this->a['student']->id, $this->b['student']->id]);
    }

    private function teacher(string $name, array $classroomIds, int $subjectId): Teacher
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        foreach ($classroomIds as $id) {
            $teacher->classrooms()->attach($id, ['subject_id' => $subjectId]);
        }

        return $teacher;
    }

    private function key(Teacher $teacher): string
    {
        return hash('sha256', 'messenger:teacher:'.$teacher->user_id);
    }

    private function parentPayload(Student $student, Teacher $teacher, string $body): array
    {
        return ['teacher_id' => $teacher->user_id, 'student_id' => $student->id, 'body' => $body, 'client_request_id' => (string) Str::uuid()];
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    private function incoming(string $child, string $body, ?Teacher $teacher = null): Message
    {
        $teacher ??= $this->sharedTeacher;
        $student = $this->{$child}['student'];
        $this->actingAs($this->parent)->post('/parent/messages?student='.$student->id, $this->parentPayload($student, $teacher, 'سؤال ولي الأمر عن '.$student->user->name))->assertRedirect();
        $this->work();
        $conversation = Conversation::where('created_by', $this->parent->id)->where('student_id', $student->id)
            ->whereHas('participants', fn ($query) => $query->whereKey($teacher->user_id))->sole();
        $this->actingAs($teacher->user)->post('/teacher/messages', [
            'conversation_id' => $conversation->id, 'body' => $body, 'client_request_id' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->work();

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'sender_id' => $teacher->user_id, 'body' => $body]);

        return $conversation->messages()->latest('id')->firstOrFail();
    }

    private function inbox(string $child, ?Teacher $teacher = null, array $headers = [])
    {
        $parameters = ['student' => $this->{$child}['student']->id];
        if ($teacher) {
            $parameters['thread'] = $this->key($teacher);
        }

        return $this->actingAs($this->parent)->get(route('parent.messages', $parameters), $headers)
            ->assertOk()->assertViewIs('parent.messages');
    }

    private function receipt(Message $message): ConversationMessageReceipt
    {
        return ConversationMessageReceipt::where('message_id', $message->id)->where('recipient_user_id', $this->parent->id)->sole();
    }

    private function broadcast(array $children, string $body)
    {
        $studentUsers = array_map(fn ($key) => $this->{$key}['user']->id, $children);
        $preview = $this->actingAs($this->sharedTeacher->user)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people', 'student_ids' => $studentUsers,
            'title' => 'رسالة المعلمة المشتركة', 'message' => $body,
        ])->assertOk()->viewData('previewId');
        $this->post('/teacher/notifications', ['preview_id' => $preview, 'confirmed' => 1])->assertRedirect();
        $this->work();

        return $this->parent->notifications()->get()->sole(fn ($notification) => ($notification->data['category'] ?? null) === 'teacher_notification' && str_ends_with($notification->data['body'], $body));
    }

    private function storedBroadcast(array $data)
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => ParentPortalNotification::class,
            'notifiable_type' => $this->parent->getMorphClass(), 'notifiable_id' => $this->parent->id,
            'data' => json_encode($data + ['title' => 'رسالة قديمة', 'category' => 'teacher_notification', 'teacher_message_id' => $id], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        TeacherMessageReceipt::create([
            'notification_id' => $id, 'teacher_user_id' => $this->sharedTeacher->user_id,
            'recipient_user_id' => $this->parent->id, 'dispatch_id' => (string) Str::uuid(),
        ]);

        return $this->parent->notifications()->findOrFail($id);
    }

    private function assertChildUrl(string $url, int $studentId): void
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame((string) $studentId, $query['student'] ?? null, $url);
    }

    public function test_selected_child_excludes_sibling_contacts_bodies_latest_preview_and_unread_but_keeps_sibling_badge_count(): void
    {
        $a = $this->incoming('a', 'PRIVATE-A-ملاحظة خاصة بأحمد');
        $this->travel(1)->seconds();
        $b = $this->incoming('b', 'PRIVATE-B-ملاحظة خاصة بليلى');
        $this->incoming('b', 'ONLY-B-ملاحظة من معلم ليلى', $this->onlyB);
        $response = $this->inbox('a', $this->sharedTeacher);
        $inbox = $response->viewData('inbox');
        $this->assertSame($this->a['student']->id, $inbox['selectedChildId']);
        $this->assertSame([$this->a['student']->id], array_column($inbox['childChoices'], 'id'));
        $this->assertSame([$a->conversation_id], $inbox['selectedThread']['conversation_ids']);
        $this->assertSame('PRIVATE-A-ملاحظة خاصة بأحمد', $inbox['selectedThread']['preview']);
        $this->assertSame(1, $inbox['selectedThread']['unread']);
        $this->assertSame(1, $inbox['childUnreadCounts'][$this->a['student']->id]);
        $this->assertSame(2, $inbox['childUnreadCounts'][$this->b['student']->id]);
        $this->assertNotContains($this->onlyB->user->name, collect($inbox['threads'])->pluck('name')->all());
        $response->assertSee('PRIVATE-A-ملاحظة خاصة بأحمد')->assertDontSee('PRIVATE-B-ملاحظة خاصة بليلى')->assertDontSee('ONLY-B-ملاحظة من معلم ليلى');
        $response->assertSee($this->b['user']->name.' — 2 رسائل غير مقروءة');
        $this->get('/parent/dashboard?student='.$this->a['student']->id)->assertOk()
            ->assertSee($this->b['user']->name.' — 2 رسائل غير مقروءة')->assertDontSee('PRIVATE-B-ملاحظة خاصة بليلى');
        $this->assertNull($this->receipt($b)->fresh()->delivered_at);
        $this->assertNull($this->receipt($b)->fresh()->viewed_at);
        $this->assertNull($this->parent->notifications()->whereKey($this->receipt($b)->notification_id)->sole()->read_at);
    }

    public function test_visible_read_actions_are_bound_to_the_explicit_child_and_other_child_tab_cannot_mark_them_viewed(): void
    {
        $a = $this->incoming('a', 'قراءة أحمد فقط.');
        $b = $this->incoming('b', 'قراءة ليلى فقط.');
        $bInbox = $this->inbox('b', $this->sharedTeacher)->viewData('inbox');
        $aInbox = $this->inbox('a', $this->sharedTeacher)->viewData('inbox');
        $this->postJson($aInbox['readAction'])->assertOk();
        $this->assertNotNull($this->receipt($a)->fresh()->viewed_at);
        $this->assertNull($this->receipt($b)->fresh()->viewed_at);
        $this->assertNull($this->parent->notifications()->whereKey($this->receipt($b)->notification_id)->sole()->read_at);

        $forged = preg_replace('/([?&]student=)'.$this->b['student']->id.'(?=&|$)/', '${1}'.$this->a['student']->id, $bInbox['readAction']);
        $this->postJson($forged)->assertNotFound();
        $this->assertNull($this->receipt($b)->fresh()->viewed_at);
        $this->postJson($bInbox['readAction'])->assertOk();
        $this->assertNotNull($this->receipt($b)->fresh()->viewed_at);
        $this->assertNotNull($this->parent->notifications()->whereKey($this->receipt($b)->notification_id)->sole()->read_at);
    }

    public function test_shared_broadcast_persists_both_student_ids_once_and_appears_in_each_child_without_sibling_private_history(): void
    {
        $this->incoming('b', 'PRIVATE-B-NOT-A-محادثة خاصة بليلى.');
        $notification = $this->broadcast(['a', 'b'], 'SHARED-A-B-تعليمات للطفلين.');
        $this->assertEqualsCanonicalizing([$this->a['student']->id, $this->b['student']->id], $notification->data['student_ids']);
        $this->assertNull($notification->data['student_id']);
        $this->assertSame(1, $this->parent->notifications()->where('data->category', 'teacher_notification')->count());
        foreach (['a', 'b'] as $child) {
            $response = $this->inbox($child, $this->sharedTeacher);
            $rows = collect($response->viewData('inbox')['messages'])->where('is_broadcast', true);
            $this->assertCount(1, $rows);
            $this->assertSame($notification->data['body'], $rows->sole()['body']);
            $this->assertChildUrl($rows->sole()['viewed_url'], $this->{$child}['student']->id);
            if ($child === 'a') {
                $response->assertDontSee('PRIVATE-B-NOT-A-محادثة خاصة بليلى.');
            }
        }
        $this->assertSame(1, TeacherMessageReceipt::whereKey($notification->id)->count());
        $this->postJson($rows->sole()['viewed_url'])->assertOk();
        $counts = $this->inbox('a')->viewData('inbox')['childUnreadCounts'];
        $this->assertSame(0, $counts[$this->a['student']->id]);
        $this->assertSame(1, $counts[$this->b['student']->id]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_b_only_broadcast_does_not_leak_into_a_or_allow_a_context_to_mark_it_viewed(): void
    {
        $notification = $this->broadcast(['b'], 'BROADCAST-B-معلومات تخص ليلى فقط.');
        $receipt = TeacherMessageReceipt::whereKey($notification->id)->sole();
        $a = $this->inbox('a', $this->sharedTeacher);
        $a->assertDontSee('BROADCAST-B-معلومات تخص ليلى فقط.');
        $this->assertSame(0, $a->viewData('inbox')['selectedThread']['unread']);
        $this->assertSame(1, $a->viewData('inbox')['childUnreadCounts'][$this->b['student']->id]);
        $this->get('/teacher-messages/'.$notification->id.'?student='.$this->a['student']->id)->assertNotFound();
        $this->postJson('/teacher-messages/'.$notification->id.'/viewed?student='.$this->a['student']->id)->assertNotFound();
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->assertNull($notification->fresh()->read_at);
        $b = $this->inbox('b', $this->sharedTeacher)->viewData('inbox');
        $row = collect($b['messages'])->sole();
        $this->postJson($row['viewed_url'])->assertOk();
        $this->assertNotNull($receipt->fresh()->viewed_at);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_legacy_broadcast_names_do_not_infer_child_scope_but_a_valid_scalar_id_remains_supported(): void
    {
        $unsafe = $this->storedBroadcast(['body' => 'UNSCOPED-بخصوص: '.$this->a['user']->name.' و '.$this->b['user']->name, 'student_id' => null]);
        $safe = $this->storedBroadcast(['body' => 'LEGACY-A-رسالة ذات رقم ابن صريح.', 'student_id' => $this->a['student']->id]);
        $this->storedBroadcast(['body' => 'MALFORMED-أسماء لا تنشئ صلاحيات.', 'student_ids' => $this->a['user']->name]);
        $a = $this->inbox('a', $this->sharedTeacher);
        $a->assertSee('LEGACY-A-رسالة ذات رقم ابن صريح.')->assertDontSee('UNSCOPED-بخصوص:')->assertDontSee('MALFORMED-أسماء لا تنشئ صلاحيات.');
        $this->assertSame(1, $a->viewData('inbox')['childUnreadCounts'][$this->a['student']->id]);
        $b = $this->inbox('b', $this->sharedTeacher);
        $b->assertDontSee('LEGACY-A-رسالة ذات رقم ابن صريح.')->assertDontSee('UNSCOPED-بخصوص:');
        $this->assertSame(0, $b->viewData('inbox')['childUnreadCounts'][$this->b['student']->id]);
        $this->assertNull(TeacherMessageReceipt::findOrFail($unsafe->id)->viewed_at);
        $this->assertNull($safe->fresh()->read_at);
    }

    public function test_unlinked_or_malformed_child_query_and_hidden_child_mismatch_cannot_read_or_send(): void
    {
        $this->incoming('b', 'PRIVATE-B-GUARDED-بيانات لا تظهر لابن آخر.');
        foreach ([$this->outside['student']->id, 0, -1, 'invalid', $this->a['student']->id.'suffix', [$this->a['student']->id]] as $id) {
            $this->actingAs($this->parent)->get(route('parent.messages', ['student' => $id]))->assertNotFound()->assertDontSee('PRIVATE-B-GUARDED-بيانات لا تظهر لابن آخر.');
        }
        $this->get(route('parent.messages', ['student' => $this->a['student']->id, 'thread' => $this->key($this->onlyB)]))->assertNotFound();
        $count = Message::count();
        $this->get('/parent/dashboard?student='.$this->a['student']->id)->assertOk();
        $this->post('/parent/messages', $this->parentPayload($this->b['student'], $this->sharedTeacher, 'الابن لا يطابق الاختيار المحفوظ.'))->assertNotFound();
        $this->post('/parent/messages?student='.$this->a['student']->id, $this->parentPayload($this->b['student'], $this->sharedTeacher, 'لا يجوز تغيير الابن المخفي.'))->assertNotFound();
        $this->post('/parent/messages?student='.$this->outside['student']->id, $this->parentPayload($this->outside['student'], $this->sharedTeacher, 'ابن غير مرتبط.'))->assertNotFound();
        $this->assertSame($count, Message::count());
        $this->guardian->students()->detach($this->b['student']);
        $this->get(route('parent.messages', ['student' => $this->b['student']->id]))->assertNotFound();
    }

    public function test_dashboard_child_selection_persists_for_navigation_but_polling_another_tab_does_not_replace_it(): void
    {
        $this->actingAs($this->parent)->get('/parent/dashboard?student='.$this->a['student']->id)->assertOk();
        $this->inbox('b', null, ['X-Messenger-Refresh' => '1']);
        $a = $this->get('/parent/messages')->assertOk()->viewData('inbox');
        $this->assertSame($this->a['student']->id, $a['selectedChildId']);
        $this->get('/parent/dashboard?student='.$this->b['student']->id)->assertOk();
        $b = $this->get('/parent/messages')->assertOk()->viewData('inbox');
        $this->assertSame($this->b['student']->id, $b['selectedChildId']);
        $this->guardian->students()->detach($this->b['student']);
        $fallback = $this->get('/parent/messages')->assertOk()->viewData('inbox');
        $this->assertSame($this->a['student']->id, $fallback['selectedChildId']);
    }

    public function test_opening_a_child_profile_updates_its_navigation_and_subsequent_inbox_context(): void
    {
        $this->actingAs($this->parent)->get('/parent/dashboard?student='.$this->a['student']->id)->assertOk();
        $this->get('/parent/children/'.$this->b['student']->id)->assertOk()
            ->assertSee($this->b['user']->name)
            ->assertSee(route('parent.messages', ['student' => $this->b['student']->id]), false)
            ->assertDontSee(route('parent.messages', ['student' => $this->a['student']->id]), false);
        $inbox = $this->get('/parent/messages')->assertOk()->viewData('inbox');
        $this->assertSame($this->b['student']->id, $inbox['selectedChildId']);
        $this->assertContains($this->onlyB->user->name, collect($inbox['threads'])->pluck('name')->all());
        $this->assertNotContains($this->onlyA->user->name, collect($inbox['threads'])->pluck('name')->all());
    }

    public function test_legacy_conversation_list_and_show_do_not_leak_or_mark_a_different_childs_messages(): void
    {
        $a = $this->incoming('a', 'LEGACY-A-PREVIEW-آخر رسالة تخص أحمد.');
        $b = $this->incoming('b', 'LEGACY-B-PREVIEW-آخر رسالة تخص ليلى.');
        $sentinel = '2026-10-20 10:00:00';
        $b->conversation->participants()->updateExistingPivot($this->parent->id, ['last_read_at' => $sentinel]);
        $this->actingAs($this->parent)->get('/parent/conversations?student='.$this->a['student']->id)->assertOk()
            ->assertSee('LEGACY-A-PREVIEW-آخر رسالة تخص أحمد.')->assertDontSee('LEGACY-B-PREVIEW-آخر رسالة تخص ليلى.')
            ->assertViewHas('conversations', fn ($conversations) => $conversations->modelKeys() === [$a->conversation_id]);
        $this->get('/parent/conversations/'.$b->conversation_id.'?student='.$this->a['student']->id)->assertNotFound()
            ->assertDontSee('LEGACY-B-PREVIEW-آخر رسالة تخص ليلى.');
        $this->assertSame($sentinel, DB::table('conversation_participants')->where('conversation_id', $b->conversation_id)->where('user_id', $this->parent->id)->value('last_read_at'));
        $this->assertNull($this->receipt($b)->fresh()->viewed_at);
        $this->assertNull($this->parent->notifications()->whereKey($this->receipt($b)->notification_id)->sole()->read_at);
    }

    public function test_parent_notification_feed_filters_child_messages_keeps_general_school_alerts_and_rejects_cross_child_read(): void
    {
        $this->incoming('a', 'NOTIFICATION-A-ملاحظة خاصة بأحمد.');
        $bMessage = $this->incoming('b', 'NOTIFICATION-B-ملاحظة خاصة بليلى.');
        $this->broadcast(['b'], 'B-ONLY-ALERT-رسالة المعلمة عن ليلى.');
        $this->parent->notify(new ParentPortalNotification([
            'title' => 'SCHOOL-GENERAL-تنبيه المدرسة العام', 'body' => 'تنبيه عام لكل أولياء الأمور.', 'category' => 'general',
        ]));
        $this->actingAs($this->parent)->get('/parent/notifications?student='.$this->a['student']->id)->assertOk()
            ->assertSee('NOTIFICATION-A-ملاحظة خاصة بأحمد.')->assertDontSee('NOTIFICATION-B-ملاحظة خاصة بليلى.')
            ->assertDontSee('B-ONLY-ALERT-رسالة المعلمة عن ليلى.')->assertSee('SCHOOL-GENERAL-تنبيه المدرسة العام');
        $this->get('/parent/notifications?student='.$this->b['student']->id)->assertOk()
            ->assertDontSee('NOTIFICATION-A-ملاحظة خاصة بأحمد.')->assertSee('NOTIFICATION-B-ملاحظة خاصة بليلى.')
            ->assertSee('B-ONLY-ALERT-رسالة المعلمة عن ليلى.')->assertSee('SCHOOL-GENERAL-تنبيه المدرسة العام');
        $receipt = $this->receipt($bMessage);
        $notification = $this->parent->notifications()->whereKey($receipt->notification_id)->sole();
        $this->post(route('parent.notifications.read', ['notification' => $notification->id, 'student' => $this->a['student']->id]))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->post(route('parent.notifications.read', ['notification' => $notification->id, 'student' => $this->b['student']->id]))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($receipt->fresh()->viewed_at);
    }

    public function test_inbox_thread_send_read_pagination_and_notification_links_retain_their_explicit_child(): void
    {
        $incoming = $this->incoming('b', 'رسالة أولى لليلى.');
        $conversation = $incoming->conversation;
        for ($index = 0; $index < 52; $index++) {
            $conversation->messages()->create(['sender_id' => $this->parent->id, 'body' => 'سجل سابق '.$index]);
        }
        $this->incoming('b', 'رسالة أحدث لليلى.');
        $inbox = $this->inbox('b', $this->sharedTeacher)->viewData('inbox');
        foreach ([$inbox['selectedThread']['url'], $inbox['sendAction'], $inbox['readAction'], $inbox['moreUrl']] as $url) {
            $this->assertIsString($url);
            $this->assertChildUrl($url, $this->b['student']->id);
        }
        $inboxWithoutChildQuery = $this->get(route('parent.messages', ['thread' => $this->key($this->sharedTeacher)]))
            ->assertOk()->viewData('inbox');
        $this->assertSame($this->b['student']->id, $inboxWithoutChildQuery['selectedChildId']);
        $this->assertIsString($inboxWithoutChildQuery['moreUrl']);
        $this->assertChildUrl($inboxWithoutChildQuery['moreUrl'], $this->b['student']->id);
        $this->assertNull($this->receipt($incoming)->fresh()->viewed_at);
        $notificationsWithoutChildQuery = $this->get(route('parent.notifications'))->assertOk()->viewData('notifications');
        $this->assertChildUrl($notificationsWithoutChildQuery->url(2), $this->b['student']->id);

        $invalid = $this->post($inbox['sendAction'], $this->parentPayload($this->b['student'], $this->sharedTeacher, ''))->assertRedirect()->assertSessionHasErrors('body');
        $this->assertChildUrl($invalid->headers->get('Location'), $this->b['student']->id);

        $broadcast = $this->broadcast(['b'], 'رابط رسالة ليلى يفتح سياقها.');
        $this->assertChildUrl($broadcast->data['url'], $this->b['student']->id);
        $destination = app(NotificationDestination::class)->for($this->parent, $broadcast->data, false);
        $this->assertChildUrl($destination, $this->b['student']->id);
    }
}
