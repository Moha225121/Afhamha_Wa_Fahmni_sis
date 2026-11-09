<?php

namespace App\Services;

use App\Contracts\FcmAccessTokenProvider;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FcmService
{
    public function __construct(private readonly FcmAccessTokenProvider $tokens) {}

    /** @param array{title: string, body: string, url?: string} $payload */
    public function sendToUser(User $user, array $payload, ?int $eventId = null): void
    {
        $projectId = config('services.firebase.project_id');
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('Firebase project configuration is missing.');
        }

        $accessToken = $this->tokens->getAccessToken();
        if (! $accessToken) {
            throw new RuntimeException('Firebase server credentials are missing or unavailable.');
        }

        $destination = FcmDestination::forUser($user, $payload['url'] ?? null);
        $data = [
            'title' => mb_substr(strip_tags($payload['title']), 0, 150),
            'body' => mb_substr(strip_tags($payload['body']), 0, 300),
            'url' => $destination,
            'recipient_user_id' => (string) $user->id,
        ];
        if ($eventId !== null) {
            $data['event_id'] = (string) $eventId;
        }

        $failures = [];
        foreach ($user->fcmDevices()->get() as $device) {
            // A token can move to another account after the initial device query.
            if (! $user->fcmDevices()->whereKey($device->id)->where('token_hash', $device->token_hash)->exists()) {
                continue;
            }
            if ($eventId !== null && DB::table('fcm_notification_deliveries')->where('event_id', $eventId)->where('device_id', $device->id)->exists()) {
                continue;
            }
            try {
                $response = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(15)
                    ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                        'message' => ['token' => $device->token, 'data' => $data],
                    ]);
            } catch (ConnectionException) {
                $failures[] = 'connection error';
                continue;
            }

            if ($this->isInvalidToken($response)) {
                $user->fcmDevices()->whereKey($device->getKey())->where('token_hash', $device->token_hash)->delete();
                continue;
            }

            if (! $response->successful()) {
                $failures[] = 'status '.$response->status();
                continue;
            }

            if ($eventId !== null) {
                DB::table('fcm_notification_deliveries')->insertOrIgnore([
                    'event_id' => $eventId,
                    'device_id' => $device->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('FCM HTTP v1 delivery failed: '.implode(', ', array_unique($failures)).'.');
        }
    }

    private function isInvalidToken(Response $response): bool
    {
        $details = $response->json('error.details', []);
        foreach (is_array($details) ? $details : [] as $detail) {
            if (($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return true;
            }
        }

        return false;
    }
}
