<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

class FcmService
{
    public function __construct(private readonly FcmConfiguration $configuration) {}

    /** @return array{device_ids: array, endpoint_hashes: array} Successfully delivered devices only. */
    public function send(User $user, array $payload): array
    {
        $delivered = ['device_ids' => [], 'endpoint_hashes' => []];
        if (! $this->configuration->enabled() || $user->status !== 'active') {
            return $delivered;
        }

        try {
            $tokens = $user->fcmTokens()->get()->keyBy('token_hash');
            if ($tokens->isEmpty()) {
                return $delivered;
            }
            // Resolve lazily so absent credentials cannot break normal pages or database notifications.
            $messaging = app(Messaging::class);
            $message = CloudMessage::new()->withData([
                'notification_id' => (string) ($payload['notification_id'] ?? Str::uuid()),
                'recipient_id' => (string) $user->id,
                // Byte limits leave room for Arabic text inside FCM's 4 KB envelope.
                'title' => mb_strcut((string) ($payload['title'] ?? ''), 0, 240, 'UTF-8'),
                'body' => mb_strcut((string) ($payload['body'] ?? ''), 0, 1200, 'UTF-8'),
                'url' => mb_strcut((string) ($payload['url'] ?? '/'), 0, 1000, 'UTF-8'),
                'category' => mb_strcut((string) ($payload['category'] ?? 'general'), 0, 80, 'UTF-8'),
            ] + (in_array($payload['category'] ?? null, ['teacher_notification', 'conversation_message'], true) && is_string($payload['receipt_url'] ?? null) && strlen($payload['receipt_url']) <= 1200
                ? ['receipt_url' => $payload['receipt_url']] : []))
                ->withWebPushConfig(['headers' => ['TTL' => '3600', 'Urgency' => 'normal']]);

            foreach ($tokens->chunk(100) as $chunk) {
                $report = $messaging->sendMulticast($message, $chunk->pluck('token')->all());
                foreach ($report->getItems() as $item) {
                    $token = $chunk->get(hash('sha256', $item->target()->value()));
                    if (! $token) {
                        continue;
                    }
                    if ($item->isSuccess()) {
                        $delivered['device_ids'][] = $token->device_id;
                        $delivered['endpoint_hashes'][] = $token->endpoint_hash;
                    } elseif ($item->messageWasSentToUnknownToken() || $item->messageTargetWasInvalid()) {
                        // Check the hash too: a concurrent registration may have rotated/reassigned it.
                        $user->fcmTokens()->whereKey($token->id)->where('token_hash', $token->token_hash)->delete();
                    } else {
                        Log::warning('FCM delivery failed; legacy push remains available.', ['user_id' => $user->id, 'device_record_id' => $token->id]);
                    }
                }
            }
        } catch (Throwable) {
            // SDK exceptions may contain tokens, credentials paths, or provider response bodies.
            Log::warning('FCM is unavailable; legacy push remains available.', ['user_id' => $user->id]);
        }

        return $delivered;
    }
}
