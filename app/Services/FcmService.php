<?php

namespace App\Services;

use App\Contracts\FcmAccessTokenProvider;
use App\Models\FcmDevice;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmService
{
    public function __construct(private readonly FcmAccessTokenProvider $tokens) {}

    /** @param array{title: string, body: string, url?: string} $payload */
    public function sendToUser(User $user, array $payload): void
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
        ];

        foreach ($user->fcmDevices()->get() as $device) {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send', [
                    'message' => ['token' => $device->token, 'data' => $data],
                ]);

            if ($this->isInvalidToken($response)) {
                $device->delete();
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('FCM HTTP v1 delivery failed with status '.$response->status().'.');
            }
        }
    }

    private function isInvalidToken(Response $response): bool
    {
        $details = $response->json('error.details', []);
        foreach (is_array($details) ? $details : [] as $detail) {
            if (($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && in_array($detail['errorCode'] ?? null, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                return true;
            }
        }

        return false;
    }
}
