<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function send(User $user, array $payload): void
    {
        $config = config('services.web_push');

        if (! $config['public_key'] || ! $config['private_key'] || ! class_exists('Minishlink\\WebPush\\WebPush')) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $config['subject'],
                    'publicKey' => $config['public_key'],
                    'privateKey' => $config['private_key'],
                ],
            ]);

            foreach ($user->pushSubscriptions()->get() as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    json_encode(array_replace($payload, ['recipient_user_id' => (string) $user->id]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    Log::warning('Unable to send parent web push notification.', ['status_code' => $report->getResponse()?->getStatusCode()]);
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Parent web push notification failed.', ['exception_type' => $exception::class]);
        }
    }
}
