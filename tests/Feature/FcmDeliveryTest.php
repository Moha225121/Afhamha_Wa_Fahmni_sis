<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FcmService;
use App\Services\ParentNotificationService;
use App\Services\PushNotificationService;
use App\Services\WebPushService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\SenderIdMismatch;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\TestCase;

class FcmDeliveryTest extends TestCase
{
    use ConfiguresFcm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
    }

    public function test_dispatch_preserves_database_notification_scopes_recipients_and_excludes_successful_fcm_device_from_vapid(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $device = $this->tokenFor($user, 'current-recipient-token');
        $this->tokenFor($other, 'foreign-recipient-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->withArgs(function ($message, $tokens) use ($user): bool {
            $data = $message->jsonSerialize();

            return $tokens === ['current-recipient-token'] && $data['data']['recipient_id'] === (string) $user->id
                && $data['data']['title'] === 'New result' && ! isset($data['notification']) && isset($data['data']['notification_id']);
        })->andReturn(MulticastSendReport::withItems([SendReport::success(MessageTarget::with('token', $device->token), ['name' => 'accepted'])]));
        $this->mock(WebPushService::class)->shouldReceive('send')->once()->withArgs(fn ($recipient, $payload, $devices, $endpoints) => $recipient->is($user) && $devices === [$device->device_id] && $endpoints === [$device->endpoint_hash]
        );
        $result = app(ParentNotificationService::class)->send($user, ['title' => 'New result', 'body' => 'Your result is ready.']);
        $this->assertSame(['fcm_accepted' => 1, 'web_push_accepted' => 0], $result);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_invalid_expired_and_wrong_project_tokens_are_removed_while_transient_failure_is_retained(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $expired = $this->tokenFor($user, 'expired-token');
        $invalid = $this->tokenFor($user, 'invalid-token');
        $mismatch = $this->tokenFor($user, 'wrong-project-token');
        $retry = $this->tokenFor($user, 'retry-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems([
            SendReport::failure(MessageTarget::with('token', $expired->token), NotFound::becauseTokenNotFound($expired->token)),
            SendReport::failure(MessageTarget::with('token', $invalid->token), new InvalidMessage('Invalid registration token')),
            SendReport::failure(MessageTarget::with('token', $mismatch->token), new SenderIdMismatch('Wrong project')),
            SendReport::failure(MessageTarget::with('token', $retry->token), new InvalidMessage('A non-token payload problem')),
        ]));
        $result = app(FcmService::class)->send($user, ['title' => 'Notice', 'body' => 'Message']);
        $this->assertSame(['device_ids' => [], 'endpoint_hashes' => []], $result);
        $this->assertModelMissing($expired);
        $this->assertModelMissing($invalid);
        $this->assertModelMissing($mismatch);
        $this->assertModelExists($retry);
    }

    public function test_configuration_or_delivery_failure_keeps_database_notifications_and_legacy_push_and_never_logs_secrets(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $this->tokenFor($user, 'sensitive-registration-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->andThrow(new RuntimeException('SECRET-PRIVATE-KEY sensitive-registration-token'));
        $this->mock(WebPushService::class)->shouldReceive('send')->once()->withArgs(fn ($recipient, $payload) => $recipient->is($user));
        Log::spy();
        app(ParentNotificationService::class)->send($user, ['title' => 'Notice', 'body' => 'Message']);
        Log::shouldHaveReceived('warning')->with('FCM is unavailable; legacy push remains available.', ['user_id' => $user->id])->once();
        $this->assertModelExists($token);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_disabled_fcm_never_resolves_credentials_and_legacy_still_receives_push(): void
    {
        config()->set('fcm.enabled', false);
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $user = User::factory()->create(['status' => 'active']);
        $this->tokenFor($user, 'stored-but-disabled-token');
        $this->mock(WebPushService::class)->shouldReceive('send')->once();
        app(PushNotificationService::class)->send($user, ['title' => 'Notice', 'body' => 'Message']);
    }

    public function test_missing_credentials_are_caught_without_a_network_request(): void
    {
        $this->assertTrue($this->app->bound(Messaging::class));
        config()->set('firebase.projects.app.credentials', base_path('missing-private-service-account.json'));
        $user = User::factory()->create(['status' => 'active']);
        $this->tokenFor($user, 'registered-device-token');
        $this->mock(WebPushService::class)->shouldReceive('send')->once();
        app(ParentNotificationService::class)->send($user, ['title' => 'Notice', 'body' => 'Message']);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_inactive_recipients_are_not_sent_a_push(): void
    {
        $user = User::factory()->create(['status' => 'inactive']);
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $this->mock(WebPushService::class)->shouldNotReceive('send');
        app(PushNotificationService::class)->send($user, ['title' => 'Notice', 'body' => 'Message']);
    }

    public function test_arabic_payload_stays_below_fcm_size_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->tokenFor($user, 'unicode-payload-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->withArgs(function ($message): bool {
            return strlen(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) < 4096;
        })->andReturn(MulticastSendReport::withItems([]));
        app(FcmService::class)->send($user, ['title' => str_repeat('عنوان', 200), 'body' => str_repeat('رسالة طويلة', 1000), 'url' => '/parent/notifications']);
    }

    public function test_legacy_service_sends_to_other_devices_and_skips_fcm_device_across_worker_scopes(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $device = $this->tokenFor($user, 'accepted-token');
        foreach (['parent', 'root'] as $scope) {
            $user->pushSubscriptions()->create(['device_id' => $device->device_id, 'endpoint' => 'https://push.example.test/'.$scope, 'public_key' => 'key', 'auth_token' => 'auth']);
        }
        $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/another', 'public_key' => 'key', 'auth_token' => 'auth']);
        $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/endpoint-only', 'public_key' => 'key', 'auth_token' => 'auth']);
        config()->set('services.web_push', ['public_key' => 'key', 'private_key' => 'private', 'subject' => 'mailto:test@example.test']);
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once()->withArgs(fn ($subscription) => $subscription->getEndpoint() === 'https://push.example.test/another');
        $client->shouldReceive('flush')->once()->andReturn((function () {
            yield from [];
        })());
        $service = new class($client) extends WebPushService
        {
            public function __construct(private WebPush $client) {}

            protected function createClient(array $auth): WebPush
            {
                return $this->client;
            }
        };
        $service->send($user, ['title' => 'Notice'], [$device->device_id], [hash('sha256', 'https://push.example.test/endpoint-only')]);
    }

    public function test_legacy_acceptance_count_excludes_failed_reports_and_preserves_accepted_count_on_exception(): void
    {
        config()->set('services.web_push', ['public_key' => 'key', 'private_key' => 'private', 'subject' => 'mailto:test@example.test']);
        $user = User::factory()->create(['status' => 'active']);
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('flush')->once()->andReturn((function () {
            yield new MessageSentReport(new Request('POST', 'https://push.example.test/one'), new Response(201), true);
            yield new MessageSentReport(new Request('POST', 'https://push.example.test/two'), new Response(503), false);
            throw new RuntimeException('Provider transport error');
        })());
        $service = new class($client) extends WebPushService
        {
            public function __construct(private WebPush $client) {}

            protected function createClient(array $auth): WebPush
            {
                return $this->client;
            }
        };
        $this->assertSame(1, $service->send($user, ['title' => 'Notice']));
    }
}
