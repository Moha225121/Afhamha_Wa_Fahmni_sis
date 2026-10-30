<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

class PushLoginSession
{
    private const KEY = 'push_login_reminder';

    public function start(Request $request): string
    {
        $id = (string) Str::uuid();
        $this->session($request)->put(self::KEY, ['user_id' => $this->userId($request), 'id' => $id]);

        return $id;
    }

    public function id(Request $request): string
    {
        $state = $this->session($request)->get(self::KEY);
        if (! is_array($state) || ($state['user_id'] ?? null) !== $this->userId($request)
            || ! is_string($state['id'] ?? null) || ! Str::isUuid($state['id'])) {
            return $this->start($request);
        }

        // A public reminder nonce, never the authentication session ID or a CSRF token.
        return $state['id'];
    }

    private function session(Request $request): Session
    {
        return $request->hasSession() ? $request->session() : app('session.store');
    }

    private function userId(Request $request): int|string
    {
        return $request->user()?->getAuthIdentifier() ?? auth()->id()
            ?? throw new LogicException('Push reminders require an authenticated user.');
    }
}
