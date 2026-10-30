<?php

namespace Tests\Feature;

use App\Jobs\SendConversationMessage;
use App\Models\Conversation;
use App\Models\ConversationMessageReceipt;
use App\Models\Guardian;
use App\Models\Message;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use App\Services\ParentNotificationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class PortalMessengerTest extends TestCase
{
    use ConfiguresFcm, CreatesStudentEducationFixtures, RefreshDatabase;

    private array $a;

    private array $b;

    private array $outside;

    private User $parent;

    private Guardian $guardian;

    private User $coParent;

    private Teacher $teacherA;

    private Teacher $teacherB;

    private Teacher $outsideTeacher;

    private User $admin;

    private array $subjects = [];

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
            $this->{$key} = $this->createStudentFixture('PORTAL-CHAT-'.$key);
            $this->{$key}['user']->update(['name' => $name]);
            $this->{$key}['classroom']->update(['name' => 'الصف الأول', 'section' => strtoupper($key)]);
            $this->subjects[$key] = $this->createSubjectFor($this->{$key}['classroom'], 'PORTAL-CHAT-'.$key);
        }
        $this->teacherA = $this->teacherFor('معلمة الشعبة A', 'a');
        $this->teacherB = $this->teacherFor('معلمة الشعبة B', 'b');
        $this->outsideTeacher = $this->teacherFor('معلم أسرة أخرى', 'outside');
        [$this->parent, $this->guardian] = $this->parentFor('ولي أحمد وليلى', [$this->a['student'], $this->b['student']]);
        [$this->coParent] = $this->parentFor('ولي آخر لأحمد', [$this->a['student']]);
        $this->admin = $this->createAdmin();
        $this->admin->update(['name' => 'حساب الإدارة غير المستهدف بالمراسلة']);
    }

    private function teacherFor(string $name, string $classKey): Teacher
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $teacher->classrooms()->attach($this->{$classKey}['classroom']->id, ['subject_id' => $this->subjects[$classKey]->id]);

        return $teacher;
    }

    private function parentFor(string $name, array $children): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'status' => 'active']);
        $guardian->students()->attach(collect($children)->pluck('id')->all());

        return [$user, $guardian];
    }

    private function parentPayload(array $overrides = []): array
    {
        return array_replace([
            'teacher_id' => $this->teacherA->user_id, 'student_id' => $this->a['student']->id,
            'body' => 'رسالة خاصة من ولي الأمر لمعلم ابنه.', 'client_request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    private function parentSend(array $payload = [], ?User $parent = null)
    {
        $response = $this->actingAs($parent ?? $this->parent)->post('/parent/messages', $this->parentPayload($payload));
        $this->drainMessages();

        return $response;
    }

    private function drainMessages(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    private function conversation(?User $parent = null, ?Student $student = null): Conversation
    {
        return Conversation::where('created_by', ($parent ?? $this->parent)->id)
            ->where('student_id', ($student ?? $this->a['student'])->id)->sole();
    }

    private function inbox(User $user, ?string $key = null, array $headers = [], ?int $studentId = null): array
    {
        $url = '/'.$user->role.'/messages'.($key !== null ? '?thread='.urlencode($key) : '');
        if ($studentId !== null) {
            $url .= ($key !== null ? '&' : '?').'student='.$studentId;
        }
        $response = $this->actingAs($user)->get($url, $headers)->assertOk()->assertViewIs($user->isParent() ? 'parent.messages' : 'shared.messenger');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        return $response->viewData('inbox');
    }

    private function teacherThread(array $inbox, User $teacher): array
    {
        return collect($inbox['threads'])->firstWhere('name', $teacher->name) ?? [];
    }

    private function broadcast(string $body): void
    {
        $preview = $this->actingAs($this->teacherA->user)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people', 'student_ids' => [$this->a['user']->id],
            'title' => 'رسالة من المعلم', 'message' => $body,
        ])->assertOk()->viewData('previewId');
        $this->post('/teacher/notifications', ['preview_id' => $preview, 'confirmed' => 1])->assertRedirect();
        $this->drainMessages();
    }

    public function test_parent_inbox_offers_only_active_teachers_of_own_children_and_server_rejects_admin_or_wrong_branch_targets(): void
    {
        $inbox = $this->inbox($this->parent);
        $names = collect($inbox['threads'])->pluck('name')->all();
        $this->assertContains($this->teacherA->user->name, $names);
        $this->assertNotContains($this->teacherB->user->name, $names);
        $otherChild = $this->inbox($this->parent, null, [], $this->b['student']->id);
        $this->assertContains($this->teacherB->user->name, collect($otherChild['threads'])->pluck('name')->all());
        $this->assertNotContains($this->teacherA->user->name, collect($otherChild['threads'])->pluck('name')->all());
        $this->inbox($this->parent, null, [], $this->a['student']->id);
        $this->assertNotContains($this->outsideTeacher->user->name, $names);
        $this->assertNotContains($this->admin->name, $names);
        foreach ([
            ['teacher_id' => $this->admin->id],
            ['teacher_id' => $this->outsideTeacher->user_id],
            ['teacher_id' => $this->teacherB->user_id],
            ['student_id' => $this->b['student']->id],
            ['student_id' => $this->outside['student']->id],
        ] as $overrides) {
            $this->parentSend($overrides)->assertNotFound();
        }
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->parentSend()->assertRedirect();
        $conversation = $this->conversation();
        $this->assertSame($this->parent->id, $conversation->creator->id);
        $this->assertEqualsCanonicalizing([$this->parent->id, $this->teacherA->user_id], $conversation->participants()->pluck('users.id')->all());
        $this->assertSame($this->parent->id, $conversation->messages()->sole()->sender_id);
    }

    public function test_parent_can_start_with_every_own_child_teacher_without_any_prior_messages_across_grade_branches(): void
    {
        $this->b['classroom']->update(['academic_year_id' => $this->a['classroom']->academic_year_id]);
        $this->outside['classroom']->update(['academic_year_id' => $this->a['classroom']->academic_year_id, 'section' => 'C']);
        $secondTeacherA = $this->teacherFor('معلم آخر لأحمد لم يرسل من قبل', 'a');
        $sharedTeacher = $this->teacherFor('معلم أحمد وليلى في جميع الشعب', 'a');
        $sharedTeacher->classrooms()->attach($this->b['classroom']->id, ['subject_id' => $this->subjects['b']->id]);
        $classroomsBefore = DB::table('classrooms')->orderBy('id')->get()->toJson();
        $assignmentsBefore = DB::table('teacher_assignments')->orderBy('id')->get()->toJson();
        $linksBefore = DB::table('guardian_student')->orderBy('guardian_id')->orderBy('student_id')->get()->toJson();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('teacher_message_receipts', 0);

        $inbox = $this->inbox($this->parent, null, [], $this->a['student']->id);
        $threads = collect($inbox['threads']);
        $this->assertCount(3, $threads);
        $this->assertEqualsCanonicalizing([
            $this->teacherA->user->name, $secondTeacherA->user->name,
            $sharedTeacher->user->name,
        ], $threads->pluck('name')->all());
        $otherChild = $this->inbox($this->parent, null, [], $this->b['student']->id);
        $this->assertEqualsCanonicalizing([$this->teacherB->user->name, $sharedTeacher->user->name], collect($otherChild['threads'])->pluck('name')->all());
        $this->assertNotContains($this->outsideTeacher->user->name, $threads->pluck('name')->all());
        $this->assertNotContains($this->admin->name, $threads->pluck('name')->all());

        foreach ([
            [$this->teacherA, [$this->a['student']->id], $this->a['student']],
            [$secondTeacherA, [$this->a['student']->id], $this->a['student']],
            [$this->teacherB, [$this->b['student']->id], $this->b['student']],
            [$sharedTeacher, [$this->b['student']->id], $this->b['student']],
        ] as [$teacher, $childIds, $student]) {
            $thread = $this->teacherThread($student->id === $this->a['student']->id ? $inbox : $otherChild, $teacher->user);
            $this->assertSame('ابدأ محادثة', $thread['preview']);
            $this->assertSame(0, $thread['unread']);
            $selected = $this->inbox($this->parent, $thread['key'], [], $student->id);
            $this->assertTrue($selected['canSend']);
            $this->assertSame(route('parent.messages.store', ['student' => $student->id]), $selected['sendAction']);
            $this->assertSame($teacher->user_id, $selected['sendFields']['teacher_id']);
            $this->assertEqualsCanonicalizing($childIds, array_column($selected['childChoices'], 'id'));
            $this->assertCount(0, $selected['messages']);
            $this->parentSend([
                'teacher_id' => $teacher->user_id, 'student_id' => $student->id,
                'body' => 'يبدأ ولي الأمر هذه المحادثة دون أن يراسله المعلم أولاً.',
            ])->assertRedirect();
            $conversation = Conversation::where('student_id', $student->id)
                ->whereHas('participants', fn ($query) => $query->whereKey($teacher->user_id))->sole();
            $this->assertEqualsCanonicalizing([$this->parent->id, $teacher->user_id], $conversation->participants()->pluck('users.id')->all());
            $this->assertSame($this->parent->id, $conversation->messages()->sole()->sender_id);
            $this->assertSame(1, $teacher->user->notifications()->where('data->category', 'conversation_message')->count());
        }

        $this->assertDatabaseCount('conversations', 4);
        $this->assertDatabaseCount('messages', 4);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertSame($classroomsBefore, DB::table('classrooms')->orderBy('id')->get()->toJson());
        $this->assertSame($assignmentsBefore, DB::table('teacher_assignments')->orderBy('id')->get()->toJson());
        $this->assertSame($linksBefore, DB::table('guardian_student')->orderBy('guardian_id')->orderBy('student_id')->get()->toJson());
    }

    public static function invalidTeachingStates(): array
    {
        return array_map(fn (string $state) => [$state], [
            'assignment_removed', 'curriculum_removed', 'subject_inactive', 'teacher_profile_inactive',
            'teacher_account_inactive', 'child_profile_inactive', 'child_account_inactive',
        ]);
    }

    #[DataProvider('invalidTeachingStates')]
    public function test_new_parent_message_revalidates_the_active_child_teacher_and_curriculum_pair(string $state): void
    {
        match ($state) {
            'assignment_removed' => $this->teacherA->classrooms()->detach($this->a['classroom']->id),
            'curriculum_removed' => $this->subjects['a']->classrooms()->detach($this->a['classroom']->id),
            'subject_inactive' => $this->subjects['a']->update(['status' => 'inactive']),
            'teacher_profile_inactive' => $this->teacherA->update(['status' => 'inactive']),
            'teacher_account_inactive' => $this->teacherA->user->update(['status' => 'inactive']),
            'child_profile_inactive' => $this->a['student']->update(['status' => 'inactive']),
            'child_account_inactive' => $this->a['user']->update(['status' => 'inactive']),
        };
        $this->parentSend()->assertNotFound();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_parent_cannot_continue_an_old_thread_after_losing_the_child_link_or_current_teacher_assignment(): void
    {
        $this->parentSend()->assertRedirect();
        $conversation = $this->conversation();
        $this->guardian->students()->detach($this->a['student']->id);
        $this->parentSend()->assertNotFound();
        $this->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'رد بعد إزالة الرابط.'])->assertNotFound();
        $this->guardian->students()->attach($this->a['student']);
        $this->a['student']->update(['classroom_id' => $this->b['classroom']->id]);
        $this->parentSend()->assertNotFound();
        $this->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'رد لمعلم الشعبة القديمة.'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_legacy_parent_conversation_create_rejects_admin_targets_and_accepts_the_current_active_teacher(): void
    {
        $payload = [
            'student_id' => $this->a['student']->id,
            'recipient_id' => $this->admin->id,
            'subject' => 'سؤال بخصوص الطالب',
            'body' => 'رسالة من النموذج السابق.',
        ];
        $this->actingAs($this->parent)->post('/parent/conversations', $payload)->assertNotFound();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);

        $payload['recipient_id'] = $this->teacherA->user_id;
        $this->post('/parent/conversations', $payload)->assertRedirect();
        $conversation = $this->conversation();
        $this->assertSame($payload['body'], $conversation->messages()->sole()->body);
        $this->assertEqualsCanonicalizing([$this->parent->id, $this->teacherA->user_id], $conversation->participants()->pluck('users.id')->all());
        $this->assertSame(1, $this->teacherA->user->notifications()->count());
    }

    public function test_legacy_parent_reply_rechecks_the_current_teacher_assignment_before_saving_an_old_thread_reply(): void
    {
        $this->actingAs($this->parent)->post('/parent/conversations', [
            'student_id' => $this->a['student']->id,
            'recipient_id' => $this->teacherA->user_id,
            'body' => 'سؤال قديم للمعلمة المسندة.',
        ])->assertRedirect();
        $conversation = $this->conversation();
        $this->teacherA->classrooms()->detach($this->a['classroom']->id);

        $this->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'رد بعد إزالة إسناد المعلمة.'])->assertForbidden();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(1, $this->teacherA->user->notifications()->count());
    }

    public function test_student_inbox_is_read_only_and_never_exposes_parent_teacher_discussions_even_for_its_own_student_id(): void
    {
        $private = 'مناقشة خاصة بين ولي الأمر والمعلمة لا تظهر للطالب.';
        $this->parentSend(['body' => $private])->assertRedirect();
        $broadcast = 'تعليمات المعلمة الموجهة لحساب الطالب نفسه.';
        $this->broadcast($broadcast);
        $inbox = $this->inbox($this->a['user']);
        $thread = $this->teacherThread($inbox, $this->teacherA->user);
        $this->assertNotEmpty($thread);
        $selected = $this->inbox($this->a['user'], $thread['key']);
        $this->assertFalse($selected['canSend']);
        $this->assertContains($broadcast, collect($selected['messages'])->pluck('body')->all());
        $this->assertNotContains($private, collect($selected['messages'])->pluck('body')->all());
        $this->assertSame([], collect($selected['messages'])->filter(fn ($row) => ! $row['is_broadcast'])->all());
        $this->get('/student/messages?thread='.urlencode($thread['key']))->assertOk()
            ->assertSee($broadcast)->assertDontSee($private)->assertDontSee('data-pm-composer', false);
        $this->post('/student/messages', ['body' => 'رد غير مسموح.'])->assertRedirect()->assertSessionHasErrors();
        $this->post('/parent/messages', $this->parentPayload())->assertForbidden();
        $this->post('/teacher/messages', ['conversation_id' => $this->conversation()->id, 'body' => 'رد غير مسموح.', 'client_request_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_coparent_of_the_same_child_cannot_see_the_other_parents_private_messages(): void
    {
        $private = 'رسالة خاصة بحساب ولي الأمر الآخر فقط.';
        $this->parentSend(['body' => $private], $this->coParent)->assertRedirect();
        $inbox = $this->inbox($this->parent);
        $thread = $this->teacherThread($inbox, $this->teacherA->user);
        $selected = $this->inbox($this->parent, $thread['key']);
        $this->assertNotContains($private, collect($selected['messages'])->pluck('body')->all());
        $otherConversation = $this->conversation($this->coParent);
        $this->get('/parent/conversations/'.$otherConversation->id)->assertNotFound()->assertDontSee($private);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_teacher_can_reply_to_own_parent_thread_but_assignment_alone_does_not_grant_other_teachers_access(): void
    {
        $private = 'رسالة لعضوي المحادثة فقط.';
        $this->parentSend(['body' => $private])->assertRedirect();
        $conversation = $this->conversation();
        $inbox = $this->inbox($this->teacherA->user);
        $thread = collect($inbox['threads'])->sole();
        $selected = $this->inbox($this->teacherA->user, $thread['key']);
        $this->assertTrue($selected['canSend']);
        $this->assertContains($private, collect($selected['messages'])->pluck('body')->all());
        $reply = ['conversation_id' => $conversation->id, 'body' => 'رد المعلمة لأحمد وولي أمره.', 'client_request_id' => (string) Str::uuid()];
        $this->actingAs($this->teacherA->user)->post('/teacher/messages', $reply)->assertRedirect();
        $this->post('/teacher/messages', $reply)->assertRedirect();
        $this->drainMessages();
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame($this->teacherA->user_id, $conversation->messages()->latest('id')->first()->sender_id);
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->assertSame(0, $this->a['user']->notifications()->count());
        $otherTeacher = $this->teacherFor('معلم آخر لنفس الصف لكنه ليس طرفًا', 'a');
        $otherInbox = $this->inbox($otherTeacher->user);
        $this->assertEmpty($otherInbox['threads']);
        $this->actingAs($otherTeacher->user)->get('/teacher/messages?thread='.urlencode($thread['key']))->assertNotFound()->assertDontSee($private);
        $reply['client_request_id'] = (string) Str::uuid();
        $this->post('/teacher/messages', $reply)->assertNotFound();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_parent_message_survives_provider_outage_and_repeated_request_uuid_does_not_duplicate_message_or_notification(): void
    {
        $this->configureFcm();
        $this->tokenFor($this->teacherA->user, 'synthetic-parent-chat-provider-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->andThrow(new RuntimeException('FAKE-PROVIDER-OFFLINE-PRIVATE'));
        $payload = $this->parentPayload(['body' => 'رسالة محفوظة رغم انقطاع تنبيه الهاتف.']);
        $this->parentSend($payload)->assertRedirect();
        $this->parentSend($payload)->assertRedirect();
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame($payload['body'], Message::sole()->body);
        $this->assertSame(1, $this->teacherA->user->notifications()->count());
        $this->assertSame('conversation_message', $this->teacherA->user->notifications()->sole()->data['category']);
        $changed = array_replace($payload, ['body' => 'لا يجوز إعادة استخدام المفتاح لرسالة مختلفة.']);
        $response = $this->actingAs($this->parent)->postJson('/parent/messages', $changed);
        $this->assertContains($response->getStatusCode(), [409, 422]);
        $this->assertDatabaseCount('messages', 1);
        $this->inbox($this->teacherA->user);
    }

    public function test_sync_default_keeps_parent_messages_and_teacher_replies_on_the_encrypted_database_queue(): void
    {
        config(['queue.default' => 'sync']);
        $payload = $this->parentPayload();
        $this->actingAs($this->parent)->post('/parent/messages', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/parent/messages', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('conversation_message_receipts', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);

        $row = DB::table('jobs')->sole();
        $this->assertSame('notifications', $row->queue);
        $command = json_decode($row->payload, true)['data']['command'];
        $this->assertStringNotContainsString('SendConversationMessage', $command);
        $job = unserialize(Crypt::decrypt($command));
        $this->assertInstanceOf(SendConversationMessage::class, $job);
        $this->assertSame(Message::sole()->id, $job->messageId);
        $this->assertSame($this->teacherA->user_id, $job->recipientId);
        $this->assertSame(ConversationMessageReceipt::sole()->notification_id, $job->notificationId);
        $this->drainMessages();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, $this->teacherA->user->notifications()->count());

        $reply = ['conversation_id' => $this->conversation()->id, 'body' => 'رد المعلمة مع الإعداد الافتراضي المتزامن.', 'client_request_id' => (string) Str::uuid()];
        $this->actingAs($this->teacherA->user)->post('/teacher/messages', $reply)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/teacher/messages', $reply)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $this->parent->notifications()->count());
        $this->drainMessages();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->assertSame('sync', config('queue.default'));
    }

    #[DataProvider('invalidMessageQueueConfigurations')]
    public function test_invalid_explicit_message_queue_is_rejected_before_any_message_or_notification_is_saved(array $configuration): void
    {
        config($configuration);
        $this->actingAs($this->parent)->postJson('/parent/messages', $this->parentPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        foreach (['conversations', 'messages', 'conversation_message_receipts', 'notifications', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function invalidMessageQueueConfigurations(): array
    {
        return [
            'database connection incorrectly uses sync driver' => [['queue.connections.database.driver' => 'sync']],
            'queue would write outside the message transaction' => [['queue.connections.database.connection' => 'unverified-database']],
        ];
    }

    public function test_queued_private_message_failures_and_retries_never_expose_sql_bindings_or_private_bodies(): void
    {
        $body = 'PRIVATE-MESSAGE-SENTINEL-'.Str::uuid().' تفاصيل خاصة بين ولي الأمر والمعلم.';
        $this->actingAs($this->parent)->post('/parent/messages?student='.$this->a['student']->id, $this->parentPayload(['body' => $body]))
            ->assertRedirect();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('jobs', 1);
        $receipt = ConversationMessageReceipt::sole();
        $databaseFailure = new QueryException('sqlite', 'insert into notifications (data) values (?)', [json_encode(['body' => $body], JSON_UNESCAPED_UNICODE)],
            new RuntimeException('PRIVATE-DATABASE-DIAGNOSTIC-SENTINEL'));
        $this->assertStringContainsString($body, $databaseFailure->getMessage());
        $this->mock(ParentNotificationService::class)->shouldReceive('sendOnce')->twice()->andThrow($databaseFailure);
        $job = Queue::connection('database')->pop('notifications');
        $this->assertNotNull($job);

        foreach ([1, 2] as $attempt) {
            try {
                $job->fire();
                $this->fail('A failed delivery attempt must remain retryable by throwing a sanitized exception.');
            } catch (RuntimeException $error) {
                $this->assertSame(RuntimeException::class, $error::class);
                $this->assertSame('Conversation message delivery could not be completed.', $error->getMessage());
                $this->assertNull($error->getPrevious());
                $this->assertStringNotContainsString($body, (string) $error);
                $this->assertStringNotContainsString('PRIVATE-DATABASE-DIAGNOSTIC-SENTINEL', (string) $error);
                $this->assertStringNotContainsString('insert into notifications', (string) $error);
            }
        }

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($receipt->fresh()->delivered_at);
        $this->assertNull($receipt->fresh()->viewed_at);
    }

    public function test_chat_list_selected_thread_and_polling_never_mark_messages_viewed_until_the_owned_visible_read_action(): void
    {
        $this->parentSend(['body' => 'رسالة غير مشاهدة للمعلمة.'])->assertRedirect();
        $first = Message::sole();
        $this->parentSend(['body' => 'رسالة ولي آخر غير مختارة.'], $this->coParent)->assertRedirect();
        $other = Message::where('id', '!=', $first->id)->sole();
        $inbox = $this->inbox($this->teacherA->user);
        $thread = collect($inbox['threads'])->first(fn ($row) => str_contains($row['name'], $this->parent->name));
        $this->assertNotNull($thread);
        $selected = $this->inbox($this->teacherA->user, $thread['key']);
        $this->inbox($this->teacherA->user, $thread['key'], ['X-Messenger-Refresh' => '1']);
        $this->assertNull(DB::table('conversation_message_receipts')->where('message_id', $first->id)->value('viewed_at'));
        $this->assertNull(DB::table('conversation_message_receipts')->where('message_id', $other->id)->value('viewed_at'));
        $this->assertNull($this->teacherA->user->notifications()->where('data->conversation_id', $first->conversation_id)->sole()->read_at);
        $this->assertNotEmpty($selected['readAction']);
        $this->postJson($selected['readAction'])->assertOk();
        $this->assertNotNull(DB::table('conversation_message_receipts')->where('message_id', $first->id)->value('viewed_at'));
        $this->assertNull(DB::table('conversation_message_receipts')->where('message_id', $other->id)->value('viewed_at'));
        $this->assertNotNull($this->teacherA->user->notifications()->where('data->conversation_id', $first->conversation_id)->sole()->read_at);
    }

    public function test_visible_read_snapshot_does_not_mark_a_newer_incoming_message_that_was_not_rendered(): void
    {
        $this->parentSend(['body' => 'الرسالة الظاهرة وقت فتح المحادثة.'])->assertRedirect();
        $first = Message::sole();
        $thread = collect($this->inbox($this->teacherA->user)['threads'])->sole();
        $rendered = $this->inbox($this->teacherA->user, $thread['key']);
        $this->parentSend(['body' => 'رسالة جديدة وصلت بعد عرض الصفحة.'])->assertRedirect();
        $newer = Message::where('id', '!=', $first->id)->sole();

        $this->actingAs($this->teacherA->user)->postJson($rendered['readAction'])->assertOk();
        $this->assertNotNull(DB::table('conversation_message_receipts')->where('message_id', $first->id)->value('viewed_at'));
        $this->assertNull(DB::table('conversation_message_receipts')->where('message_id', $newer->id)->value('viewed_at'));

        $updated = $this->inbox($this->teacherA->user, $thread['key']);
        $this->postJson($updated['readAction'])->assertOk();
        $this->assertNotNull(DB::table('conversation_message_receipts')->where('message_id', $newer->id)->value('viewed_at'));
    }

    public function test_encrypted_notification_job_rechecks_current_assignment_before_notifying_the_old_teacher(): void
    {
        $this->actingAs($this->parent)->post('/parent/messages', $this->parentPayload())->assertRedirect();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->teacherA->classrooms()->detach($this->a['classroom']->id);

        $this->drainMessages();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('messages', 1);
        $this->assertNull(DB::table('conversation_message_receipts')->sole()->delivered_at);
    }

    public function test_new_inbox_preserves_teacher_broadcast_view_receipts_until_the_visible_message_view_post(): void
    {
        $this->broadcast('رسالة معلم لا يجوز تعليمها مشاهدة بمجرد فتح القائمة.');
        $receipt = TeacherMessageReceipt::where('recipient_user_id', $this->a['user']->id)->sole();
        $inbox = $this->inbox($this->a['user']);
        $thread = $this->teacherThread($inbox, $this->teacherA->user);
        $selected = $this->inbox($this->a['user'], $thread['key']);
        $this->inbox($this->a['user'], $thread['key'], ['X-Messenger-Refresh' => '1']);
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->assertNull($this->a['user']->notifications()->sole()->read_at);
        $row = collect($selected['messages'])->firstWhere('is_broadcast', true);
        $this->assertNotEmpty($row['viewed_url']);
        $this->postJson($row['viewed_url'])->assertOk();
        $this->assertNotNull($receipt->fresh()->viewed_at);
        $this->assertNotNull($this->a['user']->notifications()->sole()->read_at);
    }

    public function test_whatsapp_style_thread_renders_full_escaped_arabic_body_with_correct_child_context(): void
    {
        $body = "سؤال لولي الأمر عن الطالب.\n".str_repeat('تفصيل عربي مهم. ', 60).'<img src=x onerror="messengerAttack()">';
        $this->parentSend(['body' => $body])->assertRedirect();
        $inbox = $this->inbox($this->parent);
        $thread = $this->teacherThread($inbox, $this->teacherA->user);
        $selected = $this->inbox($this->parent, $thread['key']);
        $this->assertSame([$this->a['student']->id], array_column($selected['childChoices'], 'id'));
        $this->get('/parent/messages?thread='.urlencode($thread['key']))->assertOk()
            ->assertSee('data-messenger', false)->assertSee('data-pm-search', false)->assertSee('data-pm-composer', false)
            ->assertSee(e($body), false)->assertDontSee('<img src=x onerror="messengerAttack()">', false)
            ->assertSee($this->teacherA->user->name)->assertSee($this->a['user']->name);
    }

    public function test_combined_inbox_explicitly_casts_integer_and_uuid_source_ids_to_the_same_text_type(): void
    {
        $privateBody = 'رسالة محادثة برقم عددي.';
        $broadcastBody = 'نسخة رسالة معلم ذات معرّف UUID.';
        $this->parentSend(['body' => $privateBody])->assertRedirect();
        $this->broadcast($broadcastBody);
        $thread = $this->teacherThread($this->inbox($this->parent), $this->teacherA->user);
        $unionQueries = [];
        DB::listen(function (QueryExecuted $event) use (&$unionQueries): void {
            if (str_contains(strtolower($event->sql), 'union all')) {
                $unionQueries[] = $event->sql;
            }
        });

        $messages = collect($this->inbox($this->parent, $thread['key'])['messages']);
        $this->assertCount(2, $messages);
        $this->assertSame($privateBody, $messages->firstWhere('is_broadcast', false)['body']);
        $this->assertStringContainsString($broadcastBody, $messages->firstWhere('is_broadcast', true)['body']);
        $this->assertCount(1, $unionQueries);
        // SQLite permits unlike UNION types; PostgreSQL does not coerce UUID to TEXT implicitly.
        $this->assertMatchesRegularExpression('/CAST\(id AS TEXT\) as source_id/i', $unionQueries[0]);
        $this->assertMatchesRegularExpression('/CAST\(r\.notification_id AS TEXT\) as source_id/i', $unionQueries[0]);
    }

    public function test_parent_composer_keeps_the_sent_child_selected_and_rejects_a_forged_unlinked_child_query(): void
    {
        $this->teacherA->classrooms()->attach($this->b['classroom']->id, ['subject_id' => $this->subjects['b']->id]);
        $this->parentSend(['body' => 'رسالة عن أحمد.'])->assertRedirect();
        $this->travel(1)->seconds();
        $this->inbox($this->parent, null, [], $this->b['student']->id);
        $response = $this->parentSend(['student_id' => $this->b['student']->id, 'body' => 'رسالة عن ليلى.'])->assertRedirect();
        $selected = $this->actingAs($this->parent)->get($response->headers->get('Location'))->assertOk()->viewData('inbox');
        $this->assertSame($this->b['student']->id, $selected['selectedChildId']);
        $this->assertSame([$this->b['student']->id], array_column($selected['childChoices'], 'id'));
        $this->assertNotContains('رسالة عن أحمد.', collect($selected['messages'])->pluck('body')->all());
        $this->assertContains('رسالة عن ليلى.', collect($selected['messages'])->pluck('body')->all());

        $this->get(route('parent.messages', ['thread' => $selected['selectedThread']['key'], 'student' => $this->outside['student']->id]))
            ->assertNotFound();
    }

    public function test_missing_chat_receipt_schema_keeps_existing_broadcasts_readable_and_rejects_new_chat_with_validation(): void
    {
        $body = 'رسالة معلمة محفوظة قبل تهيئة المحادثات.';
        $this->broadcast($body);
        Schema::drop('conversation_message_receipts');

        $inbox = $this->inbox($this->a['user']);
        $thread = $this->teacherThread($inbox, $this->teacherA->user);
        $student = $this->inbox($this->a['user'], $thread['key']);
        $this->assertContains($body, collect($student['messages'])->pluck('body')->all());
        $this->assertFalse($student['canSend']);
        $parent = $this->inbox($this->parent, $thread['key']);
        $this->assertFalse($parent['canSend']);
        $this->assertNull($parent['sendAction']);
        $this->parentSend()->assertRedirect()->assertSessionHasErrors('body');
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_chat_and_broadcast_dates_and_times_use_tripoli_after_utc_midnight_rollover(): void
    {
        config(['app.timezone' => 'UTC']);
        DB::table('settings')->insert(['key' => 'timezone', 'value' => 'Africa/Tripoli', 'group' => 'system']);
        $this->travelTo(Carbon::parse('2026-10-22 22:30:00', 'UTC'));
        $this->parentSend(['body' => 'رسالة خاصة في بداية اليوم بتوقيت ليبيا.'])->assertRedirect();
        $this->broadcast('رسالة للطلاب في بداية اليوم بتوقيت ليبيا.');

        $teacher = $this->inbox($this->teacherA->user);
        $thread = collect($teacher['threads'])->sole();
        $this->assertSame('00:30', $thread['time']);
        $chat = $this->inbox($this->teacherA->user, $thread['key']);
        $this->assertSame('2026/10/23', collect($chat['messages'])->sole()['date']);
        $this->assertSame('00:30', collect($chat['messages'])->sole()['time']);

        $student = $this->inbox($this->a['user']);
        $broadcast = $this->teacherThread($student, $this->teacherA->user);
        $this->assertSame('00:30', $broadcast['time']);
        $messages = $this->inbox($this->a['user'], $broadcast['key']);
        $this->assertSame('2026/10/23', collect($messages['messages'])->sole()['date']);
        $this->assertSame('00:30', collect($messages['messages'])->sole()['time']);
        $this->travelBack();
    }

    public function test_closed_thread_and_forged_cross_account_read_actions_cannot_add_or_mark_messages(): void
    {
        $this->parentSend()->assertRedirect();
        $conversation = $this->conversation();
        $inbox = $this->inbox($this->teacherA->user);
        $thread = collect($inbox['threads'])->sole();
        $selected = $this->inbox($this->teacherA->user, $thread['key']);
        $otherTeacher = $this->teacherFor('غير مشارك في المحادثة', 'a');
        $this->actingAs($otherTeacher->user)->postJson($selected['readAction'])->assertNotFound();
        $this->assertNull(DB::table('conversation_message_receipts')->where('message_id', Message::sole()->id)->value('viewed_at'));
        $conversation->update(['status' => 'closed']);
        $this->actingAs($this->teacherA->user)->post('/teacher/messages', [
            'conversation_id' => $conversation->id, 'body' => 'رد على محادثة مغلقة.', 'client_request_id' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($this->parent)->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'رد قديم مغلق.'])->assertForbidden();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_messenger_send_and_visible_read_posts_keep_normal_csrf_protection(): void
    {
        $this->parentSend()->assertRedirect();
        $conversation = $this->conversation();
        $inbox = $this->inbox($this->teacherA->user);
        $thread = collect($inbox['threads'])->sole();
        $selected = $this->inbox($this->teacherA->user, $thread['key']);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($selected['readAction'])->assertStatus(419);
        $this->post('/teacher/messages', ['conversation_id' => $conversation->id, 'body' => 'رد', 'client_request_id' => (string) Str::uuid()])->assertStatus(419);
        $this->parentSend()->assertStatus(419);
        $this->assertDatabaseCount('messages', 1);
    }
}
