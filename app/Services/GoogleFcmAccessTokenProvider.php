<?php

namespace App\Services;

use App\Contracts\FcmAccessTokenProvider;
use Google\Auth\Credentials\ServiceAccountCredentials;

class GoogleFcmAccessTokenProvider implements FcmAccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function getAccessToken(): ?string
    {
        $credentialsPath = config('services.firebase.credentials');
        if (! is_string($credentialsPath) || $credentialsPath === '' || ! is_file($credentialsPath)) {
            return null;
        }

        $credentials = new ServiceAccountCredentials([self::SCOPE], $credentialsPath);
        $token = $credentials->fetchAuthToken();

        return is_string($token['access_token'] ?? null) ? $token['access_token'] : null;
    }
}
