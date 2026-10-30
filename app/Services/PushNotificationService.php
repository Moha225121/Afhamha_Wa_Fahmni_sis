<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class PushNotificationService
{
    public function __construct(private readonly FcmService $fcm, private readonly WebPushService $webPush) {}

    /** @return array{fcm_accepted: int, web_push_accepted: int} Provider acceptance, not browser receipt. */
    public function send(User $user, array $payload): array
    {
        if ($user->status !== 'active') {
            return ['fcm_accepted' => 0, 'web_push_accepted' => 0];
        }
        $payload += ['notification_id' => (string) Str::uuid()];
        $payload['url'] = app(NotificationDestination::class)->for($user, $payload, false);
        if (in_array($payload['category'] ?? null, ['teacher_notification', 'conversation_message'], true)) {
            // The complete message is already stored. Device transports carry a small preview.
            $payload['title'] = mb_strcut((string) ($payload['title'] ?? ''), 0, 240, 'UTF-8');
            $payload['body'] = mb_strcut((string) ($payload['body'] ?? ''), 0, 1200, 'UTF-8');
            // JSON escapes can use six bytes per control character. Keep the whole envelope small.
            while (strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 3000 && $payload['body'] !== '') {
                $payload['body'] = mb_strcut($payload['body'], 0, intdiv(strlen($payload['body']), 2), 'UTF-8');
            }
        }
        $sent = $this->fcm->send($user, $payload);
        if (! $sent['device_ids'] && ! $sent['endpoint_hashes']) {
            $legacyAccepted = $this->webPush->send($user, $payload);
        } else {
            $legacyAccepted = $this->webPush->send($user, $payload, $sent['device_ids'], $sent['endpoint_hashes']);
        }

        return ['fcm_accepted' => count($sent['device_ids']), 'web_push_accepted' => $legacyAccepted];
    }
}
