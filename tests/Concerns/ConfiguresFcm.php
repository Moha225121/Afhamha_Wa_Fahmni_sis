<?php

namespace Tests\Concerns;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Str;

trait ConfiguresFcm
{
    private function configureFcm(): void
    {
        config()->set('fcm', [
            'enabled' => true,
            'web' => ['projectId' => 'test-project', 'apiKey' => 'public-test-key', 'authDomain' => 'test-project.firebaseapp.com', 'appId' => '1:123:web:test', 'messagingSenderId' => '123'],
            'vapid_key' => 'public-vapid-key',
        ]);
        $this->withoutVite();
    }

    private function tokenFor(User $user, string $token, ?string $device = null): FcmToken
    {
        return $user->fcmTokens()->create([
            'token' => $token, 'token_hash' => hash('sha256', $token),
            'device_id' => $device ?? (string) Str::uuid(),
            'endpoint_hash' => hash('sha256', 'https://push.example.test/'.$token), 'last_seen_at' => now(),
        ]);
    }
}
