<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CloseoutWebPushPrivacyTest extends TestCase
{
    public function test_web_push_uses_server_recipient_and_logs_only_failure_status(): void
    {
        config(['services.web_push.public_key' => 'synthetic-public', 'services.web_push.private_key' => 'synthetic-private']);
        $transport = Mockery::mock('overload:Minishlink\WebPush\WebPush');
        $transport->shouldReceive('__construct')->once();
        $transport->shouldReceive('queueNotification')->once()->withArgs(function ($subscription, $json): bool {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return $payload['recipient_user_id'] === '42' && $payload['title'] === 'QA';
        });
        $report = Mockery::mock(\Minishlink\WebPush\MessageSentReport::class);
        $report->shouldReceive('isSuccess')->once()->andReturn(false);
        $report->shouldReceive('getResponse')->once()->andReturn(new \GuzzleHttp\Psr7\Response(410));
        $report->shouldNotReceive('getReason');
        $transport->shouldReceive('flush')->once()->andReturn(new \ArrayIterator([$report]));
        $relation = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $relation->shouldReceive('get')->once()->andReturn(collect([(object) [
            'endpoint' => 'https://push.invalid/QA-SECRET-ENDPOINT', 'public_key' => 'cHVibGlj',
            'auth_token' => 'c3ludGhldGlj', 'content_encoding' => 'aes128gcm',
        ]]));
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 42;
        $user->shouldReceive('pushSubscriptions')->once()->andReturn($relation);
        Log::spy();
        app(WebPushService::class)->send($user, ['title' => 'QA', 'body' => 'Synthetic', 'recipient_user_id' => '99']);
        Log::shouldHaveReceived('warning')->once()->with('Unable to send parent web push notification.', ['status_code' => 410]);
    }

    public function test_provider_exception_details_cannot_log_subscription_tokens_or_credentials(): void
    {
        config(['services.web_push.public_key' => 'synthetic-public', 'services.web_push.private_key' => 'synthetic-private']);
        $transport = Mockery::mock('overload:Minishlink\WebPush\WebPush');
        $transport->shouldReceive('__construct')->once()->andThrow(new RuntimeException('QA-SECRET-ENDPOINT-TOKEN'));
        Log::spy();
        app(WebPushService::class)->send(new User(['role' => 'parent', 'status' => 'active']), ['title' => 'QA', 'body' => 'Synthetic']);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) =>
            ! str_contains(json_encode($context), 'QA-SECRET-ENDPOINT-TOKEN')
            && ($context['exception_type'] ?? null) === RuntimeException::class);
    }
}
