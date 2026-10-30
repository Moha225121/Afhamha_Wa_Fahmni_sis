<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use App\Services\TeacherMessageReceiptService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherMessageReceiptTest extends TestCase
{
    use ConfiguresFcm, CreatesStudentEducationFixtures, RefreshDatabase;

    private User $actor;

    private Teacher $teacher;

    private array $a;

    private array $b;

    private User $unlinked;

    private User $parent;

    private User $outsider;

    private array $deviceMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->configureFcm();
        config([
            'queue.connections.database.connection' => config('database.default'),
            'queue.batching.database' => config('database.default'),
            'services.web_push.public_key' => null,
            'services.web_push.private_key' => null,
        ]);
        $this->actor = User::factory()->create(['name' => 'المعلمة التجريبية', 'role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        foreach (['a' => 'أحمد التجريبي', 'b' => 'ليلى التجريبية'] as $key => $name) {
            $this->{$key} = $this->createStudentFixture('RECEIPT-'.$key);
            $this->{$key}['user']->update(['name' => $name]);
            $subject = $this->createSubjectFor($this->{$key}['classroom'], 'RECEIPT-'.$key);
            $this->teacher->classrooms()->attach($this->{$key}['classroom']->id, ['subject_id' => $subject->id]);
        }
        $this->unlinked = User::factory()->create(['name' => 'خالد بلا ولي أمر', 'role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $this->unlinked->id, 'student_number' => 'RECEIPT-UNLINKED',
            'classroom_id' => $this->a['classroom']->id, 'status' => 'active',
        ]);
        $this->parent = User::factory()->create(['name' => 'ولي أحمد وليلى', 'role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $guardian->students()->attach([$this->a['student']->id, $this->b['student']->id]);
        $outsider = $this->createStudentFixture('RECEIPT-OUTSIDE');
        $outsider['user']->update(['name' => 'حساب آخر']);
        $this->outsider = $outsider['user'];
    }

    private function dispatchMessage(string $body = 'رسالة معزولة للاختبار.', ?array $studentUserIds = null, ?User $actor = null): string
    {
        $actor ??= $this->actor;
        $preview = $this->actingAs($actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people',
            'student_ids' => $studentUserIds ?? [$this->a['user']->id, $this->b['user']->id, $this->unlinked->id],
            'title' => 'عنوان تجريبي خاص', 'message' => $body,
        ])->assertOk()->viewData('previewId');
        $this->post('/teacher/notifications', ['preview_id' => $preview, 'confirmed' => 1])
            ->assertRedirect(route('teacher.notifications.show', $preview));

        return $preview;
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    private function receipt(string $dispatch, User $recipient): TeacherMessageReceipt
    {
        return TeacherMessageReceipt::where('dispatch_id', $dispatch)->where('recipient_user_id', $recipient->id)->sole();
    }

    private function deliveryUrl(TeacherMessageReceipt $receipt): string
    {
        $url = app(TeacherMessageReceiptService::class)->deliveryUrl($receipt->notification_id);
        $this->assertIsString($url);
        $this->assertStringStartsWith('/teacher-message-receipts/'.$receipt->notification_id.'/delivered?', $url);
        $this->assertNull(parse_url($url, PHP_URL_HOST));

        return $url;
    }

    private function guest(): void
    {
        $this->app['auth']->guard()->logout();
        $this->app['auth']->forgetGuards();
    }

    private function statusRows(string $dispatch, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->actor)->get(route('teacher.notifications.show', $dispatch))
            ->assertOk()->viewData('perRecipientReceipts');
    }

    private function successfulProvider(int $calls): void
    {
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->times($calls)
            ->andReturnUsing(function ($message, array $tokens): MulticastSendReport {
                $data = $message->jsonSerialize()['data'];
                $this->deviceMessages[(int) $data['recipient_id']] = $data;

                return MulticastSendReport::withItems(array_map(
                    fn (string $token) => SendReport::success(MessageTarget::with('token', $token), ['name' => 'accepted-by-fake-provider']),
                    $tokens,
                ));
            });
    }

    public function test_messages_are_sent_before_the_worker_runs_and_provider_acceptance_does_not_claim_receipt(): void
    {
        $this->tokenFor($this->a['user'], 'receipt-student-token');
        $this->tokenFor($this->parent, 'receipt-parent-token');
        $this->successfulProvider(2);
        $dispatch = $this->dispatchMessage();
        $this->assertDatabaseCount('teacher_message_receipts', 4);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('jobs', 4);
        $this->assertSame([], $this->deviceMessages);
        $queued = $this->statusRows($dispatch);
        $this->assertCount(4, $queued);
        foreach ($queued as $row) {
            $this->assertSame('sent', $row->status);
            $this->assertSame('تم الإرسال', $row->status_label);
            $this->assertNotNull($row->sent_at);
            $this->assertNull($row->delivered_at);
            $this->assertNull($row->viewed_at);
            $this->assertSame($this->actor->id, $row->teacher_user_id);
        }
        $studentReceipt = $this->receipt($dispatch, $this->a['user']);
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()
            ->assertViewHas('stored', 4)->assertViewHas('accepted', 0)->assertDontSee('جارٍ الإرسال');

        $this->work();
        $sent = $this->statusRows($dispatch);
        foreach ($sent as $row) {
            $this->assertSame('sent', $row->status);
            $this->assertSame('تم الإرسال', $row->status_label);
            $this->assertNotNull($row->sent_at);
            $this->assertNull($row->delivered_at);
            $this->assertNull($row->viewed_at);
        }
        $this->assertSame($this->a['user']->name, $sent->firstWhere('recipient_user_id', $this->a['user']->id)->recipient->name);
        $this->assertSame($this->parent->name, $sent->firstWhere('recipient_user_id', $this->parent->id)->recipient->name);
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()
            ->assertViewHas('accepted', 2)->assertViewHas('stored', 4)
            ->assertViewHas('deliveredCount', 0)->assertViewHas('viewedCount', 0);
        foreach ($this->deviceMessages as $userId => $payload) {
            $receipt = $sent->firstWhere('recipient_user_id', $userId);
            $this->assertSame($receipt->notification_id, $payload['notification_id']);
            $destination = '/'.$receipt->recipient->role.'/messages?thread='.hash('sha256', 'messenger:teacher:'.$this->actor->id)
                .($receipt->recipient->role === 'parent' ? '&student='.$this->a['student']->id : '');
            $this->assertSame($destination, $payload['url']);
            $this->assertArrayHasKey('receipt_url', $payload);
            $this->assertStringStartsWith('/teacher-message-receipts/'.$receipt->notification_id.'/delivered?', $payload['receipt_url']);
        }
        $this->guest();
        $this->postJson($this->deviceMessages[$this->a['user']->id]['receipt_url'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertNotNull($studentReceipt->fresh()->delivered_at);
        $this->assertNull($studentReceipt->fresh()->viewed_at);
        $this->assertNull($this->receipt($dispatch, $this->parent)->delivered_at);
    }

    public function test_student_and_parent_can_read_a_message_while_the_device_queue_is_stopped(): void
    {
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $body = 'تصل هذه الرسالة إلى صندوق الوارد دون تشغيل عامل الخلفية.';
        $dispatch = $this->dispatchMessage($body, [$this->a['user']->id]);
        foreach ([$this->a['user'], $this->parent] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $receipt = $this->receipt($dispatch, $recipient);
            $this->actingAs($recipient)->get($notification->data['url'])->assertOk()->assertSee($body);
            $this->assertNotNull($receipt->fresh()->delivered_at);
            $this->assertNull($receipt->fresh()->viewed_at);
            $this->postJson(route('teacher-messages.viewed', $notification->id,
                false).($recipient->isParent() ? '?student='.$this->a['student']->id : ''))->assertOk();
            $this->assertNotNull($receipt->fresh()->viewed_at);
        }
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->actingAs($this->actor)->get(route('teacher.notifications.show', $dispatch))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('deliveredCount', 2)->assertViewHas('viewedCount', 2);
    }

    public function test_message_inboxes_and_receipts_roll_back_when_durable_queueing_fails(): void
    {
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $dispatch = $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people', 'student_ids' => [$this->a['user']->id],
            'title' => 'رسالة اختبار', 'message' => 'يجب حفظ الرسالة وطلب التنبيه معاً.',
        ])->assertOk()->viewData('previewId');
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->module === 'teacher_notifications') {
                throw new RuntimeException('private-durable-queue-failure');
            }
        });
        $this->post('/teacher/notifications', ['preview_id' => $dispatch, 'confirmed' => 1])
            ->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors('send');
        foreach (['notifications', 'teacher_message_receipts', 'jobs', 'job_batches', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_sent_total_counts_a_stored_notification_without_a_delivery_audit(): void
    {
        $dispatch = $this->dispatchMessage('تُحتسب الرسالة بمجرد حفظها.', [$this->unlinked->id]);
        $this->work();
        // This is the state between saving a notification and recording the transport outcome.
        AuditLog::where('module', 'teacher_notification_delivery')->delete();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, AuditLog::where('module', 'teacher_notification_delivery')->count());

        $response = $this->actingAs($this->actor)->get(route('teacher.notifications.show', $dispatch));
        $response->assertOk()->assertViewHas('stored', 1)->assertViewHas('accepted', 0)
            ->assertViewHas('deliveredCount', 0)->assertViewHas('viewedCount', 0);
        $this->assertSame('sent', $response->viewData('perRecipientReceipts')->sole()->status);
    }

    public function test_signed_relative_delivery_ack_is_guest_safe_idempotent_and_does_not_mark_viewed_or_read(): void
    {
        $body = 'رسالة خاصة يجب ألا تظهر في رد تأكيد الاستلام.';
        $dispatch = $this->dispatchMessage($body, [$this->unlinked->id]);
        $this->work();
        $receipt = $this->receipt($dispatch, $this->unlinked);
        $url = $this->deliveryUrl($receipt);
        $this->guest();
        $this->travel(1)->minutes();
        $response = $this->postJson($url);
        $response->assertOk()->assertExactJson(['ok' => true])->assertDontSee($body)
            ->assertDontSee($this->unlinked->name)->assertDontSee($this->actor->name);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $receipt->refresh();
        $firstDelivery = $receipt->delivered_at->toIso8601String();
        $this->assertNull($receipt->viewed_at);
        $this->assertNull($this->unlinked->notifications()->sole()->read_at);
        $this->travel(1)->minutes();
        $this->postJson($url)->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame($firstDelivery, $receipt->fresh()->delivered_at->toIso8601String());
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->getJson('/teacher-messages/'.$receipt->notification_id.'?'.parse_url($url, PHP_URL_QUERY))
            ->assertUnauthorized()->assertDontSee($body);
        $this->postJson('/teacher-messages/'.$receipt->notification_id.'/viewed?'.parse_url($url, PHP_URL_QUERY))
            ->assertUnauthorized()->assertDontSee($body);
        $this->getJson($url)->assertStatus(405)->assertDontSee($body);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('teacher_message_receipts', 1);
    }

    public function test_unsigned_tampered_expired_and_unrelated_ack_urls_cannot_change_receipts(): void
    {
        $dispatch = $this->dispatchMessage('رسالة اختبار التوقيع.', [$this->a['user']->id]);
        $this->work();
        $student = $this->receipt($dispatch, $this->a['user']);
        $parent = $this->receipt($dispatch, $this->parent);
        $url = $this->deliveryUrl($student);
        $this->guest();
        $this->postJson('/teacher-message-receipts/'.$student->notification_id.'/delivered')->assertForbidden();
        $this->postJson(preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        $this->postJson(str_replace($student->notification_id, $parent->notification_id, $url))->assertForbidden();
        $expired = URL::temporarySignedRoute('teacher-message-receipts.delivered', now()->subMinute(), ['notification' => $student->notification_id], false);
        $this->postJson($expired)->assertForbidden();
        $unknown = URL::temporarySignedRoute('teacher-message-receipts.delivered', now()->addMinute(), ['notification' => (string) Str::uuid()], false);
        $this->postJson($unknown)->assertNotFound();
        foreach ([$student, $parent] as $receipt) {
            $this->assertNull($receipt->fresh()->delivered_at);
            $this->assertNull($receipt->fresh()->viewed_at);
        }
        $this->assertNull($this->a['user']->notifications()->sole()->read_at);
        $this->assertNull($this->parent->notifications()->sole()->read_at);
    }

    public function test_recipient_detail_only_marks_delivery_and_an_authenticated_visible_view_post_marks_seen_once(): void
    {
        $body = str_repeat("رسالة عربية طويلة بمعلومات مهمة.\n", 150).'<script>alert("private-body")</script>';
        $dispatch = $this->dispatchMessage($body, [$this->a['user']->id]);
        $this->work();
        $receipt = $this->receipt($dispatch, $this->a['user']);
        $url = $this->deliveryUrl($receipt);
        $this->guest();
        $this->travel(1)->minutes();
        $this->postJson($url)->assertOk();
        $firstDelivery = $receipt->fresh()->delivered_at->toIso8601String();
        $this->travel(1)->minutes();
        $this->actingAs($this->a['user'])->get('/teacher-messages/'.$receipt->notification_id)
            ->assertOk()->assertSee(e($body), false)->assertDontSee('<script>alert("private-body")</script>', false)
            ->assertSee($this->actor->name);
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->assertNull($this->a['user']->notifications()->sole()->read_at);
        $this->postJson(route('teacher-messages.viewed', $receipt->notification_id))->assertOk()->assertExactJson(['ok' => true]);
        $receipt->refresh();
        $firstView = $receipt->viewed_at->toIso8601String();
        $firstRead = $this->a['user']->notifications()->sole()->read_at->toIso8601String();
        $this->assertSame($firstDelivery, $receipt->delivered_at->toIso8601String());
        $this->assertGreaterThanOrEqual($receipt->delivered_at->timestamp, $receipt->viewed_at->timestamp);
        $this->assertNull($this->receipt($dispatch, $this->parent)->viewed_at);
        $this->travel(1)->minutes();
        $this->get('/teacher-messages/'.$receipt->notification_id)->assertOk();
        $this->postJson(route('teacher-messages.viewed', $receipt->notification_id))->assertOk()->assertExactJson(['ok' => true]);
        $this->guest();
        $this->postJson($url)->assertOk();
        $this->assertSame($firstDelivery, $receipt->fresh()->delivered_at->toIso8601String());
        $this->assertSame($firstView, $receipt->fresh()->viewed_at->toIso8601String());
        $this->assertSame($firstRead, $this->a['user']->notifications()->sole()->read_at->toIso8601String());
        $parentReceipt = $this->receipt($dispatch, $this->parent);
        $this->actingAs($this->parent)->get('/teacher-messages/'.$parentReceipt->notification_id)
            ->assertOk()->assertSee($this->a['user']->name)->assertSee(e($body), false);
        $this->assertNotNull($parentReceipt->fresh()->delivered_at);
        $this->assertNull($parentReceipt->fresh()->viewed_at);
        $this->assertNull($this->parent->notifications()->sole()->read_at);
        $this->postJson(route('teacher-messages.viewed', $parentReceipt->notification_id))->assertOk()->assertExactJson(['ok' => true]);
        $this->assertNotNull($parentReceipt->fresh()->viewed_at);
        $this->assertNotNull($this->parent->notifications()->sole()->read_at);
        foreach ($this->statusRows($dispatch) as $row) {
            $this->assertSame('viewed', $row->status);
            $this->assertSame('تمت المشاهدة', $row->status_label);
        }
    }

    public function test_other_accounts_and_signed_delivery_links_cannot_read_or_mark_a_recipient_copy_viewed(): void
    {
        $body = 'محتوى خاص بالمستلم المحدد فقط.';
        $dispatch = $this->dispatchMessage($body, [$this->a['user']->id]);
        $this->work();
        $receipt = $this->receipt($dispatch, $this->a['user']);
        $detail = '/teacher-messages/'.$receipt->notification_id;
        $this->actingAs($this->outsider)->get($detail)->assertNotFound()->assertDontSee($body);
        $this->postJson($detail.'/viewed')->assertNotFound()->assertDontSee($body);
        $this->actingAs($this->parent)->get($detail)->assertNotFound()->assertDontSee($body);
        $this->postJson($detail.'/viewed')->assertNotFound()->assertDontSee($body);
        $this->actingAs($this->actor)->get($detail)->assertForbidden()->assertDontSee($body);
        $this->postJson($detail.'/viewed')->assertForbidden()->assertDontSee($body);
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->actingAs($otherTeacher)->get($detail)->assertForbidden()->assertDontSee($body);
        $this->postJson($detail.'/viewed')->assertForbidden()->assertDontSee($body);
        $url = $this->deliveryUrl($receipt);
        $this->guest();
        $this->getJson($detail.'?'.parse_url($url, PHP_URL_QUERY))->assertUnauthorized()->assertDontSee($body);
        $this->postJson($detail.'/viewed?'.parse_url($url, PHP_URL_QUERY))->assertUnauthorized()->assertDontSee($body);
        $this->a['user']->update(['status' => 'inactive']);
        $this->actingAs($this->a['user'])->get($detail)->assertForbidden()->assertDontSee($body);
        $this->postJson($detail.'/viewed')->assertForbidden()->assertDontSee($body);
        $this->assertNull($receipt->fresh()->delivered_at);
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->assertNull($this->a['user']->notifications()->sole()->read_at);
    }

    public function test_a_delivery_ack_requires_the_matching_stored_teacher_notification_and_recipient(): void
    {
        $dispatch = $this->dispatchMessage('رسالة مطابقة للمستلم.', [$this->unlinked->id]);
        $this->work();
        $receipt = $this->receipt($dispatch, $this->unlinked);
        $notification = $this->unlinked->notifications()->sole();
        $originalData = $notification->data;
        $url = $this->deliveryUrl($receipt);
        $notification->update(['data' => array_replace($originalData, ['category' => 'general'])]);
        $this->guest();
        $this->postJson($url)->assertNotFound();
        $notification->update(['data' => $originalData]);
        $receipt->update(['recipient_user_id' => $this->outsider->id]);
        $this->postJson($url)->assertNotFound();
        $this->actingAs($this->outsider)->get('/teacher-messages/'.$receipt->notification_id)->assertNotFound();
        $this->postJson(route('teacher-messages.viewed', $receipt->notification_id))->assertNotFound();
        $this->assertNull($receipt->fresh()->delivered_at);
        $this->assertNull($receipt->fresh()->viewed_at);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_provider_outage_leaves_messages_sent_until_an_actual_signed_receipt_arrives(): void
    {
        $this->tokenFor($this->a['user'], 'offline-student-token');
        $this->tokenFor($this->parent, 'offline-parent-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->twice()->andThrow(new RuntimeException('isolated-provider-offline'));
        $dispatch = $this->dispatchMessage('تظل الرسالة محفوظة رغم تعذر التنبيه.', [$this->a['user']->id]);
        $this->work();
        foreach ($this->statusRows($dispatch) as $row) {
            $this->assertSame('sent', $row->status);
            $this->assertNull($row->delivered_at);
            $this->assertNull($row->viewed_at);
        }
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('accepted', 0)->assertViewHas('failed', 0)
            ->assertViewHas('deliveredCount', 0)->assertViewHas('viewedCount', 0);
        $student = $this->receipt($dispatch, $this->a['user']);
        $this->guest();
        $this->postJson($this->deliveryUrl($student))->assertOk();
        $rows = $this->statusRows($dispatch);
        $this->assertSame('delivered', $rows->firstWhere('recipient_user_id', $this->a['user']->id)->status);
        $this->assertSame('تم الاستلام', $rows->firstWhere('recipient_user_id', $this->a['user']->id)->status_label);
        $this->assertSame('sent', $rows->firstWhere('recipient_user_id', $this->parent->id)->status);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_teacher_outbox_and_per_recipient_statuses_only_show_the_authors_own_dispatches(): void
    {
        $dispatch = $this->dispatchMessage('طلب المعلمة الأولى.');
        $this->work();
        $otherActor = User::factory()->create(['name' => 'معلم آخر', 'role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherActor->id, 'status' => 'active']);
        foreach ($this->teacher->classrooms()->get() as $classroom) {
            $otherTeacher->classrooms()->attach($classroom->id, ['subject_id' => $classroom->pivot->subject_id]);
        }
        $otherDispatch = $this->dispatchMessage('طلب المعلم الآخر.', [$this->unlinked->id], $otherActor);
        $this->work();
        $student = $this->receipt($dispatch, $this->a['user']);
        $parent = $this->receipt($dispatch, $this->parent);
        $this->guest();
        $this->postJson($this->deliveryUrl($student))->assertOk();
        $this->actingAs($this->parent)->get('/teacher-messages/'.$parent->notification_id)->assertOk();
        $this->postJson(route('teacher-messages.viewed', $parent->notification_id))->assertOk()->assertExactJson(['ok' => true]);
        $own = $this->statusRows($dispatch);
        $other = $this->statusRows($otherDispatch, $otherActor);
        $this->assertCount(4, $own);
        $this->assertCount(1, $other);
        $this->assertSame([], array_intersect($own->modelKeys(), $other->modelKeys()));
        $this->assertSame('delivered', $own->firstWhere('recipient_user_id', $this->a['user']->id)->status);
        $this->assertSame('viewed', $own->firstWhere('recipient_user_id', $this->parent->id)->status);
        $this->assertSame('sent', $own->firstWhere('recipient_user_id', $this->b['user']->id)->status);
        $this->assertSame($this->unlinked->name, $other->sole()->recipient->name);
        $this->actingAs($otherActor)->get(route('teacher.notifications.show', $dispatch))->assertNotFound();
        $this->actingAs($this->actor)->get(route('teacher.notifications.show', $otherDispatch))->assertNotFound();
        $ownSent = $this->get(route('teacher.notifications.sent'))->assertOk()->viewData('dispatches');
        $this->assertSame([$dispatch], $ownSent->getCollection()->map(fn ($row) => $row->new_values['dispatch_id'])->all());
        $otherSent = $this->actingAs($otherActor)->get(route('teacher.notifications.sent'))->assertOk()->viewData('dispatches');
        $this->assertSame([$otherDispatch], $otherSent->getCollection()->map(fn ($row) => $row->new_values['dispatch_id'])->all());
    }

    public function test_only_the_signed_receipt_post_is_csrf_exempt_while_teacher_send_posts_stay_protected(): void
    {
        $dispatch = $this->dispatchMessage('تأكيد استلام آمن.', [$this->unlinked->id]);
        $this->work();
        $url = $this->deliveryUrl($this->receipt($dispatch, $this->unlinked));
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->guest();
        $this->postJson($url)->assertOk()->assertExactJson(['ok' => true]);
        $this->actingAs($this->unlinked)->get('/teacher-messages/'.$this->receipt($dispatch, $this->unlinked)->notification_id)->assertOk();
        $viewUrl = route('teacher-messages.viewed', $this->receipt($dispatch, $this->unlinked)->notification_id);
        $this->postJson($viewUrl)->assertStatus(419);
        $this->assertNull($this->receipt($dispatch, $this->unlinked)->viewed_at);
        $this->withSession(['_token' => 'isolated-receipt-view-csrf-token'])
            ->postJson($viewUrl, ['_token' => 'isolated-receipt-view-csrf-token'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertNotNull($this->receipt($dispatch, $this->unlinked)->viewed_at);
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people', 'student_ids' => [$this->unlinked->id],
            'title' => 'عنوان', 'message' => 'رسالة',
        ])->assertStatus(419);
        $this->post('/teacher/notifications', ['preview_id' => $dispatch, 'confirmed' => 1])->assertStatus(419);
    }

    public function test_missing_receipt_setup_blocks_new_dispatches_before_claiming_preview_but_keeps_legacy_messages_working(): void
    {
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        Schema::drop('teacher_message_receipts');
        $preview = $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people', 'student_ids' => [$this->unlinked->id],
            'title' => 'رسالة جديدة', 'message' => 'لم تكتمل التهيئة.',
        ])->assertOk()->viewData('previewId');
        $this->postJson('/teacher/notifications', ['preview_id' => $preview, 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('send');
        $this->assertFalse(Cache::has('teacher-notification-submitted:'.$preview));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        $unknown = (string) Str::uuid();
        $this->assertNull(app(TeacherMessageReceiptService::class)->deliveryUrl($unknown));
        $url = URL::temporarySignedRoute('teacher-message-receipts.delivered', now()->addMinute(), ['notification' => $unknown], false);
        $this->guest();
        $this->postJson($url)->assertNotFound();
        $this->actingAs($this->unlinked)->getJson('/teacher-messages/'.$unknown)->assertNotFound();
        $this->postJson(route('teacher-messages.viewed', $unknown))->assertNotFound();

        $legacy = $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'student', 'recipient_id' => $this->unlinked->id,
            'title' => 'رسالة سابقة', 'message' => 'تظل الرسائل السابقة متوافقة.',
        ])->assertOk()->viewData('previewId');
        $this->post('/teacher/notifications', ['preview_id' => $legacy, 'confirmed' => 1])->assertRedirect();
        $this->work();
        $this->assertSame('تظل الرسائل السابقة متوافقة.', $this->unlinked->notifications()->sole()->data['body']);
        $this->get(route('teacher.notifications.show', $legacy))->assertOk()
            ->assertViewHas('stored', 1)->assertViewHas('perRecipientReceipts', fn ($rows) => $rows->isEmpty());
    }
}
