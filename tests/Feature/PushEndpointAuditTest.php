<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\WebPush;
use Tests\TestCase;

class PushEndpointAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_registration_rejects_non_https_and_literal_private_targets_without_network_requests(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'active']));
        foreach (['http://push.example.test/x', 'file:///private.txt', 'https://127.0.0.1/x', 'https://10.0.0.1/x', 'https://192.168.1.1/x', 'https://169.254.169.254/x', 'https://[::1]/x', 'https://[::ffff:127.0.0.1]/x', 'https://localhost/x', 'https://service.local/x', 'https://internal/x', 'https://2130706433/x', 'https://user:pass@push.example.test/x'] as $endpoint) {
            $this->postJson('/push-subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'fixture', 'auth' => 'fixture']])
                ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        }
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->postJson('/push-subscriptions', ['endpoint' => 'https://push.example.test/browser', 'keys' => ['p256dh' => 'fixture', 'auth' => 'fixture']])->assertOk();
    }

    public function test_delivery_skips_unsafe_historical_endpoints_and_keeps_a_valid_legacy_subscription(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        foreach (['https://127.0.0.1/internal', 'https://push.example.test/browser'] as $endpoint) {
            $user->pushSubscriptions()->create(['endpoint' => $endpoint, 'public_key' => 'fixture', 'auth_token' => 'fixture']);
        }
        config(['services.web_push.public_key' => 'fixture', 'services.web_push.private_key' => 'fixture']);
        $client = \Mockery::mock(WebPush::class);
        $client->shouldReceive('queueNotification')->once()->with(\Mockery::on(fn ($subscription) => $subscription->getEndpoint() === 'https://push.example.test/browser'), \Mockery::type('string'));
        $client->shouldReceive('flush')->once()->andReturn([]);
        $service = \Mockery::mock(WebPushService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createClient')->once()->andReturn($client);
        $this->assertSame(0, $service->send($user, ['title' => 'QA', 'body' => 'QA']));
        $this->assertDatabaseCount('push_subscriptions', 2);
    }
}
