<?php

namespace App\Services;

use App\Models\User;
use App\Rules\PushEndpoint;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function send(User $user, array $payload, array $excludedDeviceIds = [], array $excludedEndpointHashes = []): int
    {
        $config = config('services.web_push');
        $accepted = 0;

        if (! $config['public_key'] || ! $config['private_key'] || ! class_exists('Minishlink\\WebPush\\WebPush')) {
            return 0;
        }

        try {
            $webPush = $this->createClient([
                'VAPID' => [
                    'subject' => $config['subject'],
                    'publicKey' => $config['public_key'],
                    'privateKey' => $config['private_key'],
                ],
            ]);

            foreach ($user->pushSubscriptions()->get() as $subscription) {
                if (! PushEndpoint::allowed($subscription->endpoint)) {
                    Log::warning('Unsafe legacy push endpoint was skipped.', ['user_id' => $user->id, 'device_record_id' => $subscription->id]);

                    continue;
                }
                if (in_array($subscription->device_id, $excludedDeviceIds, true)
                    || in_array(hash('sha256', $subscription->endpoint), $excludedEndpointHashes, true)) {
                    continue;
                }
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $accepted++;
                } else {
                    Log::warning('Unable to send parent web push notification.', ['user_id' => $user->id]);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Parent web push notification failed.', ['user_id' => $user->id]);
        }

        return $accepted;
    }

    protected function createClient(array $auth): WebPush
    {
        return new WebPush($auth, client: app(SafeWebPushClient::class));
    }
}
