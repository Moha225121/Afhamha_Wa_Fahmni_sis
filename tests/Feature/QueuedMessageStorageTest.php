<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\ParentNotificationService;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class QueuedMessageStorageTest extends TestCase
{
    use RefreshDatabase;

    private ParentNotificationService $notifications;

    private PushNotificationService $push;

    private User $recipient;

    private string $notificationId = '21767c5b-9e63-4c38-8676-803e4c9dbf9e';

    private array $payload = [
        'title' => 'رسالة تجريبية',
        'body' => 'نص خاص محفوظ في صندوق الرسائل.',
        'category' => 'conversation_message',
        'conversation_id' => 123,
        'url' => '/teacher/messages?thread=test-thread',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipient = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->push = $this->mock(PushNotificationService::class);
        $this->notifications = app(ParentNotificationService::class);
    }

    private function storedData(): array
    {
        return json_decode(DB::table('notifications')->where('id', $this->notificationId)->value('data'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_storing_a_queued_message_populates_the_inbox_without_contacting_push(): void
    {
        $this->push->shouldNotReceive('send');

        $this->assertTrue($this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId));
        $notification = $this->recipient->notifications()->sole();

        $this->assertSame($this->notificationId, $notification->id);
        $this->assertSame(ParentPortalNotification::class, $notification->type);
        $this->assertSame($this->payload['body'], $notification->data['body']);
        $this->assertSame(123, $notification->data['conversation_id']);
        $this->assertTrue($notification->data['_message_push_pending']);
        $this->assertNull($notification->read_at);

        $changedPayload = array_replace($this->payload, ['body' => 'A repeated request must not replace the inbox copy.']);
        $this->assertFalse($this->notifications->storeForQueuedMessage($this->recipient, $changedPayload, $this->notificationId));
        $this->assertSame($this->payload['body'], $this->storedData()['body']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_worker_pushes_a_pre_stored_message_once_using_its_stable_notification_id(): void
    {
        $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $this->push->shouldReceive('send')->once()
            ->withArgs(function (User $user, array $payload): bool {
                return $user->is($this->recipient)
                    && $payload === $this->payload + ['notification_id' => $this->notificationId];
            })->andReturn(['fcm_accepted' => 1, 'web_push_accepted' => 0]);

        $result = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $retry = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertFalse($result['stored']);
        $this->assertTrue($result['push_attempted']);
        $this->assertSame(1, $result['fcm_accepted']);
        $this->assertFalse($retry['push_attempted']);
        $this->assertSame(0, $retry['fcm_accepted']);
        $this->assertArrayNotHasKey('_message_push_pending', $this->storedData());
        $this->assertSame($this->payload['body'], $this->storedData()['body']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_reentrant_worker_cannot_claim_the_same_push_twice(): void
    {
        $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $nestedResult = null;
        $this->push->shouldReceive('send')->once()->andReturnUsing(function () use (&$nestedResult): array {
            $nestedResult = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

            return ['fcm_accepted' => 1, 'web_push_accepted' => 0];
        });

        $result = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertTrue($result['push_attempted']);
        $this->assertFalse($nestedResult['push_attempted']);
        $this->assertFalse($nestedResult['stored']);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_legacy_delivered_notification_without_a_pending_marker_is_never_pushed_again(): void
    {
        $this->push->shouldNotReceive('send');
        DB::table('notifications')->insert([
            'id' => $this->notificationId,
            'type' => ParentPortalNotification::class,
            'notifiable_type' => $this->recipient->getMorphClass(),
            'notifiable_id' => $this->recipient->id,
            'data' => json_encode($this->payload, JSON_THROW_ON_ERROR),
            'read_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $before = DB::table('notifications')->sole();

        $result = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertFalse($result['stored']);
        $this->assertFalse($result['push_attempted']);
        $this->assertEquals($before, DB::table('notifications')->sole());
    }

    public function test_an_old_queued_message_without_an_inbox_row_is_stored_and_pushed_once(): void
    {
        $this->push->shouldReceive('send')->once()->withArgs(function (User $user, array $payload): bool {
            return $user->is($this->recipient) && $payload['notification_id'] === $this->notificationId;
        })->andReturn(['fcm_accepted' => 0, 'web_push_accepted' => 1]);

        $result = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertTrue($result['stored']);
        $this->assertTrue($result['push_attempted']);
        $this->assertSame(1, $result['web_push_accepted']);
        $this->assertSame($this->payload['body'], $this->recipient->notifications()->sole()->data['body']);
        $this->assertArrayNotHasKey('_message_push_pending', $this->storedData());
    }

    #[DataProvider('notificationOwnershipMismatches')]
    public function test_an_id_collision_cannot_push_or_change_another_notification(string $column, mixed $value): void
    {
        $this->push->shouldNotReceive('send');
        $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        DB::table('notifications')->where('id', $this->notificationId)->update([$column => $value]);
        $before = DB::table('notifications')->sole();

        $result = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertFalse($result['stored']);
        $this->assertFalse($result['push_attempted']);
        $this->assertEquals($before, DB::table('notifications')->sole());
    }

    public static function notificationOwnershipMismatches(): array
    {
        return [
            'recipient' => ['notifiable_id', 999999],
            'recipient type' => ['notifiable_type', 'unrelated-notifiable-type'],
            'notification type' => ['type', 'unrelated-notification-type'],
        ];
    }

    public function test_delayed_push_preserves_an_inbox_notification_already_read_by_its_recipient(): void
    {
        $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $readAt = now()->subMinute()->toDateTimeString();
        DB::table('notifications')->where('id', $this->notificationId)->update(['read_at' => $readAt]);
        $this->push->shouldReceive('send')->once()->andReturn(['fcm_accepted' => 0, 'web_push_accepted' => 0]);

        $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);

        $this->assertSame($readAt, DB::table('notifications')->where('id', $this->notificationId)->value('read_at'));
        $this->assertArrayNotHasKey('_message_push_pending', $this->storedData());
    }

    public function test_sender_transaction_rollback_removes_its_inbox_copy_without_sending_push(): void
    {
        $this->push->shouldNotReceive('send');
        try {
            DB::transaction(function (): void {
                $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
                $this->assertDatabaseCount('notifications', 1);
                throw new RuntimeException('Synthetic sender transaction failure.');
            });
            $this->fail('The sender transaction must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic sender transaction failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_provider_exception_preserves_the_inbox_and_the_claim_prevents_a_duplicate_attempt(): void
    {
        $this->notifications->storeForQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $this->push->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic provider outage.'));
        try {
            $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);
            $this->fail('The provider exception must reach the queue worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic provider outage.', $exception->getMessage());
        }

        $this->assertSame($this->payload['body'], $this->recipient->notifications()->sole()->data['body']);
        $this->assertArrayNotHasKey('_message_push_pending', $this->storedData());
        $retry = $this->notifications->sendQueuedMessage($this->recipient, $this->payload, $this->notificationId);
        $this->assertFalse($retry['push_attempted']);
        $this->assertDatabaseCount('notifications', 1);
    }
}
