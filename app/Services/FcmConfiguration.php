<?php

namespace App\Services;

class FcmConfiguration
{
    public function enabled(): bool
    {
        return (bool) config('fcm.enabled') && filled(config('fcm.web.projectId'));
    }

    public function webEnabled(): bool
    {
        return $this->enabled()
            && filled(config('fcm.web.apiKey'))
            && filled(config('fcm.web.appId'))
            && filled(config('fcm.web.messagingSenderId'))
            && filled(config('fcm.vapid_key'));
    }

    public function publicConfig(): array
    {
        return array_intersect_key(config('fcm.web', []), array_flip([
            'apiKey', 'authDomain', 'projectId', 'messagingSenderId', 'appId',
        ]));
    }
}
