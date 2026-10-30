<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SafeWebPushClient;
use App\Services\WebPushService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebPushTransportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_legacy_transport_uses_the_dns_pinned_client(): void
    {
        $service = app(WebPushService::class);
        $webPush = (new \ReflectionMethod($service, 'createClient'))->invoke($service, []);
        $client = (new \ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);
        $this->assertInstanceOf(SafeWebPushClient::class, $client);
    }

    public static function deliveryStatuses(): array
    {
        return [[201, 1], [404, 0], [410, 0], [503, 0]];
    }

    #[DataProvider('deliveryStatuses')]
    public function test_controlled_provider_reports_are_counted_without_deleting_subscriptions(int $status, int $accepted): void
    {
        $user = $this->recipient();
        $request = new Request('POST', 'https://push.example.test/browser');
        $realClient = (new \ReflectionMethod(WebPushService::class, 'createClient'))->invoke(app(WebPushService::class), []);
        $report = (new \ReflectionMethod(WebPush::class, 'createReport'))->invoke($realClient, $request, new Response($status));
        $client = \Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andReturn((function () use ($report) {
            yield $report;
        })());
        Log::spy();
        $this->assertSame($accepted, $this->service($client)->send($user, ['title' => 'Local fixture', 'body' => 'No live delivery']));
        $this->assertDatabaseCount('push_subscriptions', 1);
        if ($accepted === 0) {
            Log::shouldHaveReceived('warning')->once()->with('Unable to send parent web push notification.', ['user_id' => $user->id]);
        } else {
            Log::shouldNotHaveReceived('warning');
        }
    }

    public function test_malformed_historical_subscription_metadata_fails_safely_without_leaking_details(): void
    {
        $user = $this->recipient('unsupported-fixture-encoding');
        $client = \Mockery::mock(WebPush::class);
        $client->shouldNotReceive('queueNotification');
        $client->shouldNotReceive('flush');
        Log::spy();
        $this->assertSame(0, $this->service($client)->send($user, ['title' => 'Fixture', 'body' => 'Fixture']));
        $this->assertDatabaseCount('push_subscriptions', 1);
        Log::shouldHaveReceived('warning')->once()->with('Parent web push notification failed.', ['user_id' => $user->id]);
    }

    public function test_transport_exception_is_a_sanitized_zero_result_and_retains_the_registration(): void
    {
        $user = $this->recipient();
        $client = \Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once();
        $client->shouldReceive('flush')->once()->andThrow(new \RuntimeException('sensitive-fixture-marker'));
        Log::spy();
        $this->assertSame(0, $this->service($client)->send($user, ['title' => 'Fixture', 'body' => 'Fixture']));
        $this->assertDatabaseCount('push_subscriptions', 1);
        Log::shouldHaveReceived('warning')->once()->with('Parent web push notification failed.', ['user_id' => $user->id]);
    }

    private function recipient(string $encoding = 'aes128gcm'): User
    {
        config(['services.web_push.public_key' => 'fixture-only', 'services.web_push.private_key' => 'fixture-only']);
        $user = User::factory()->create(['status' => 'active']);
        $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/browser', 'public_key' => 'fixture-public', 'auth_token' => 'fixture-auth', 'content_encoding' => $encoding]);

        return $user;
    }

    private function service(WebPush $client): WebPushService
    {
        return new class($client) extends WebPushService
        {
            public function __construct(private WebPush $client) {}

            protected function createClient(array $auth): WebPush
            {
                return $this->client;
            }
        };
    }
}
