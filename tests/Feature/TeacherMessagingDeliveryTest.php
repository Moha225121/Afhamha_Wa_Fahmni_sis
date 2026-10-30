<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherNotification;
use App\Models\FcmToken;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\NotificationDestination;
use App\Services\WebPushService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Minishlink\WebPush\Encryption;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherMessagingDeliveryTest extends TestCase
{
    use ConfiguresFcm, CreatesStudentEducationFixtures, RefreshDatabase;

    private User $actor;

    private Teacher $teacher;

    private array $a;

    private array $b;

    private array $foreign;

    private User $unlinked;

    private User $parent;

    private array $deviceMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
        config([
            'queue.connections.database.connection' => config('database.default'),
            'queue.batching.database' => config('database.default'),
            'services.web_push.public_key' => null,
            'services.web_push.private_key' => null,
        ]);
        $this->actor = User::factory()->create(['name' => 'معلمة اختبار الإشعارات', 'role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        foreach (['a' => 'أحمد التجريبي', 'b' => 'ليلى التجريبية', 'foreign' => 'طالب خارج نطاق الرسالة'] as $key => $name) {
            $this->{$key} = $this->createStudentFixture('DELIVERY-'.$key);
            $this->{$key}['user']->update(['name' => $name]);
            if ($key !== 'foreign') {
                $subject = $this->createSubjectFor($this->{$key}['classroom'], 'DELIVERY-'.$key);
                $this->teacher->classrooms()->attach($this->{$key}['classroom']->id, ['subject_id' => $subject->id]);
            }
        }
        $this->unlinked = User::factory()->create(['name' => 'خالد بلا ولي أمر', 'role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $this->unlinked->id, 'student_number' => 'DELIVERY-UNLINKED',
            'classroom_id' => $this->a['classroom']->id, 'status' => 'active',
        ]);
        $this->parent = User::factory()->create(['name' => 'ولي أمر تجريبي', 'role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $guardian->students()->attach([$this->a['student']->id, $this->b['student']->id, $this->foreign['student']->id]);
    }

    private function longMessage(): string
    {
        return str_repeat("رسالة عربية طويلة للتجربة المعزولة فقط، مع الاحتفاظ بجميع التفاصيل. 🙂\n", 90)."\nنهاية الرسالة كاملة.";
    }

    private function registerDevice(User $user, string $label): FcmToken
    {
        $token = 'synthetic-delivery-token:'.str_repeat($label, 30);
        $this->actingAs($user)->postJson('/fcm-tokens', [
            'token' => $token, 'device_id' => (string) Str::uuid(),
            'endpoint' => 'https://push.example.test/teacher-delivery/'.$label,
        ])->assertOk()->assertExactJson(['ok' => true]);

        return FcmToken::where('token_hash', hash('sha256', $token))->sole();
    }

    private function preview(string $message, ?array $userIds = null): string
    {
        return $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people',
            'student_ids' => $userIds ?? [$this->a['user']->id, $this->b['user']->id, $this->unlinked->id],
            'title' => 'متابعة الدروس — اختبار معزول', 'message' => $message,
        ])->assertOk()->viewData('previewId');
    }

    private function confirm(string $previewId): void
    {
        $this->actingAs($this->actor)->post('/teacher/notifications', ['preview_id' => $previewId, 'confirmed' => 1])
            ->assertRedirect(route('teacher.notifications.show', $previewId));
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    /** Only the external provider boundary is faked: the queue and delivery services stay real. */
    private function fakeSuccessfulProvider(int $calls): void
    {
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->times($calls)
            ->andReturnUsing(function ($message, array $tokens): MulticastSendReport {
                $payload = $message->jsonSerialize();
                $data = $payload['data'];
                $this->assertArrayNotHasKey('notification', $payload);
                $this->assertLessThanOrEqual(1200, strlen($data['body']));
                $this->assertTrue(mb_check_encoding($data['body'], 'UTF-8'));
                $this->assertLessThan(4096, strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                $this->deviceMessages[(int) $data['recipient_id']][] = $data;

                return MulticastSendReport::withItems(array_map(
                    fn (string $token) => SendReport::success(MessageTarget::with('token', $token), ['name' => 'synthetic-provider-acceptance']),
                    $tokens,
                ));
            });
    }

    public function test_long_arabic_family_message_uses_real_encrypted_queue_and_full_account_storage_with_bounded_device_previews(): void
    {
        $recipients = [$this->a['user'], $this->b['user'], $this->unlinked, $this->parent];
        foreach ($recipients as $index => $recipient) {
            $this->registerDevice($recipient, 'current'.$index);
        }
        $this->registerDevice($this->foreign['user'], 'outside');
        $this->fakeSuccessfulProvider(4);
        $message = $this->longMessage();
        $this->assertGreaterThan(3000, mb_strlen($message));
        $id = $this->preview($message);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], $this->deviceMessages);

        $this->confirm($id);
        $this->assertDatabaseCount('jobs', 4);
        $this->assertDatabaseCount('job_batches', 1);
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $command = json_decode($payload, true)['data']['command'];
            $this->assertStringNotContainsString(mb_substr($message, 0, 25), $command);
            $queued = unserialize(Crypt::decrypt($command));
            $this->assertInstanceOf(SendTeacherNotification::class, $queued);
            $this->assertSame($message, $queued->message);
        }
        $this->work();
        $this->assertDatabaseCount('notifications', 4);
        $this->assertCount(4, $this->deviceMessages);
        $parentBody = 'بخصوص: '.$this->a['user']->name.'، '.$this->b['user']->name."\n\n".$message;
        foreach ($recipients as $recipient) {
            $notification = $recipient->notifications()->sole();
            $expectedBody = $recipient->isParent() ? $parentBody : $message;
            $destination = '/'.$recipient->role.'/messages?thread='.hash('sha256', 'messenger:teacher:'.$this->actor->id);
            if ($recipient->isParent()) {
                $destination .= '&student='.$this->a['student']->id;
            }
            $this->assertSame($expectedBody, $notification->data['body']);
            $this->assertSame('teacher_notification', $notification->data['category']);
            $this->assertSame($destination, $notification->data['url']);
            $this->assertSame($destination, app(NotificationDestination::class)->for($recipient, $notification->data, false));
            $device = collect($this->deviceMessages[$recipient->id])->sole();
            $this->assertSame($notification->id, $device['notification_id']);
            $this->assertSame($destination, $device['url']);
            $this->assertSame(mb_strcut($expectedBody, 0, 1200, 'UTF-8'), $device['body']);
        }
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->assertSame(0, $this->foreign['user']->notifications()->count());
        $this->assertArrayNotHasKey($this->foreign['user']->id, $this->deviceMessages);
        $this->assertStringNotContainsString($this->foreign['user']->name, $parentBody);
        $this->assertSame(0, $this->unlinked->student->guardians()->count());
        $this->actingAs($this->actor)->get(route('teacher.notifications.show', $id))
            ->assertOk()->assertViewHas('stored', 4)->assertViewHas('accepted', 4)->assertViewHas('failed', 0);
    }

    public function test_duplicate_confirmation_and_a_replayed_encrypted_job_do_not_duplicate_account_or_device_messages(): void
    {
        $this->registerDevice($this->a['user'], 'studentretry');
        $this->registerDevice($this->parent, 'parentretry');
        $this->fakeSuccessfulProvider(2);
        $id = $this->preview('رسالة إعادة المحاولة التجريبية.', [$this->a['user']->id]);
        $this->confirm($id);
        $command = json_decode(DB::table('jobs')->value('payload'), true)['data']['command'];
        $retry = unserialize(Crypt::decrypt($command));
        $this->confirm($id);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('job_batches', 1);
        $this->work();
        $this->app->call([$retry, 'handle']);
        $this->travel(1)->minutes();
        $this->confirm($id);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('job_batches', 1);
        $this->assertCount(2, $this->deviceMessages);
        $this->get(route('teacher.notifications.show', $id))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('accepted', 2)->assertViewHas('failed', 0);
    }

    public function test_provider_outage_keeps_full_account_messages_and_a_successful_result_without_exposing_provider_secrets(): void
    {
        $studentToken = $this->registerDevice($this->a['user'], 'outagestudent');
        $parentToken = $this->registerDevice($this->parent, 'outageparent');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->twice()
            ->andThrow(new RuntimeException('SYNTHETIC-PRIVATE-PROVIDER-ERROR'));
        $message = $this->longMessage();
        $id = $this->preview($message, [$this->a['user']->id]);
        $this->confirm($id);
        $this->work();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame($message, $this->a['user']->notifications()->sole()->data['body']);
        $this->assertStringEndsWith("\n\n".$message, $this->parent->notifications()->sole()->data['body']);
        $this->assertModelExists($studentToken);
        $this->assertModelExists($parentToken);
        $this->get(route('teacher.notifications.show', $id))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('accepted', 0)->assertViewHas('failed', 0)
            ->assertDontSee('SYNTHETIC-PRIVATE-PROVIDER-ERROR')->assertDontSee($studentToken->token);
    }

    public function test_a_student_deactivated_after_inbox_delivery_is_not_contacted_by_the_push_worker(): void
    {
        $device = $this->registerDevice($this->unlinked, 'inactive');
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $id = $this->preview('رسالة لطالب تجريبي بلا ولي أمر.', [$this->unlinked->id]);
        $this->confirm($id);
        $this->unlinked->update(['status' => 'inactive']);
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertModelExists($device);
        $this->get(route('teacher.notifications.show', $id))->assertOk()
            ->assertViewHas('stored', 1)->assertViewHas('skipped', 0)->assertViewHas('accepted', 0)->assertViewHas('failed', 0);
    }

    public function test_an_expired_device_token_is_removed_without_losing_the_student_or_parent_account_message(): void
    {
        $device = $this->registerDevice($this->a['user'], 'expired');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->andReturn(
            MulticastSendReport::withItems([
                SendReport::failure(MessageTarget::with('token', $device->token), NotFound::becauseTokenNotFound($device->token)),
            ]),
        );
        $id = $this->preview('رسالة اختبار لجهاز منتهي التسجيل.', [$this->a['user']->id]);
        $this->confirm($id);
        $this->work();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertModelMissing($device);
        $this->get(route('teacher.notifications.show', $id))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('accepted', 0)->assertViewHas('failed', 0);
    }

    public function test_long_control_character_message_uses_a_safe_legacy_web_push_preview_and_keeps_full_account_bodies(): void
    {
        config([
            'fcm.enabled' => false,
            'services.web_push' => ['public_key' => 'synthetic-key', 'private_key' => 'synthetic-private', 'subject' => 'mailto:test@example.test'],
        ]);
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        foreach ([$this->a['user'], $this->parent] as $index => $recipient) {
            $recipient->pushSubscriptions()->create([
                'endpoint' => 'https://push.example.test/teacher-legacy/'.$index,
                'public_key' => 'synthetic-key', 'auth_token' => 'synthetic-auth',
            ]);
        }
        $captured = [];
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->twice()->andReturnUsing(function ($subscription, string $encoded) use (&$captured): void {
            $captured[] = ['endpoint' => $subscription->getEndpoint(), 'json' => $encoded];
        });
        $client->shouldReceive('flush')->twice()->andReturnUsing(function () use (&$captured) {
            yield new MessageSentReport(new Request('POST', $captured[array_key_last($captured)]['endpoint']), new Response(201), true);
        });
        $this->app->instance(WebPushService::class, new class($client) extends WebPushService
        {
            public function __construct(private WebPush $client) {}

            protected function createClient(array $auth): WebPush
            {
                return $this->client;
            }
        });
        $message = "رسالة اختبار:\n".str_repeat("\x01", 1100)."\t\n".str_repeat("تفاصيل عربية كاملة يجب أن تبقى محفوظة.\n\t", 150).'نهاية محفوظة.';
        $this->assertGreaterThan(3000, mb_strlen($message));
        $id = $this->preview($message, [$this->a['user']->id]);
        $this->confirm($id);
        $this->work();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame($message, $this->a['user']->notifications()->sole()->data['body']);
        $this->assertSame('بخصوص: '.$this->a['user']->name."\n\n".$message, $this->parent->notifications()->sole()->data['body']);
        $this->assertCount(2, $captured);
        foreach ($captured as $record) {
            $this->assertLessThan(Encryption::MAX_PAYLOAD_LENGTH, strlen($record['json']), 'The encoded legacy payload must fit the Web Push transport, including escaped control characters.');
            $payload = json_decode($record['json'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertLessThanOrEqual(1200, strlen($payload['body']));
            $this->assertTrue(mb_check_encoding($payload['body'], 'UTF-8'));
            $recipientId = DB::table('notifications')->where('id', $payload['notification_id'])->value('notifiable_id');
            $recipient = User::findOrFail($recipientId);
            $destination = '/'.$recipient->role.'/messages?thread='.hash('sha256', 'messenger:teacher:'.$this->actor->id)
                .($recipient->isParent() ? '&student='.$this->a['student']->id : '');
            $this->assertSame($destination, $payload['url']);
            $this->assertStringStartsWith('/teacher-message-receipts/'.$payload['notification_id'].'/delivered?', $payload['receipt_url']);
        }
        $this->get(route('teacher.notifications.show', $id))->assertOk()
            ->assertViewHas('stored', 2)->assertViewHas('accepted', 2)->assertViewHas('failed', 0);
    }
}
