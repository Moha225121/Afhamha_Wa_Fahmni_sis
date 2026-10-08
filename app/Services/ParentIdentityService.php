<?php

namespace App\Services;

use App\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ParentIdentityService
{
    public const SESSION_KEY = 'parent_identity';

    public function options(Guardian $guardian): array
    {
        $options = [
            'primary' => ['name' => $guardian->user->name, 'relationship' => $guardian->relationship, 'phone' => $guardian->user->phone],
        ];

        if (filled($guardian->additional_guardian_name)) {
            $options['additional'] = [
                'name' => $guardian->additional_guardian_name,
                'relationship' => $guardian->additional_guardian_relationship,
                'phone' => $guardian->additional_guardian_phone,
            ];
        }

        return $options;
    }

    public function selectedKey(Request $request, Guardian $guardian): ?string
    {
        $options = $this->options($guardian);
        if (count($options) === 1) {
            return 'primary';
        }

        $choice = $request->session()->get(self::SESSION_KEY);
        if (! $this->validChoice($choice, $guardian, $options)) {
            $cookie = $request->cookie($this->cookieName($guardian));
            $choice = is_string($cookie) ? json_decode($cookie, true) : null;
        }

        if (! $this->validChoice($choice, $guardian, $options)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $request->session()->put(self::SESSION_KEY, $choice);

        return $choice['key'];
    }

    public function remember(Request $request, Guardian $guardian, string $key): void
    {
        abort_unless(array_key_exists($key, $this->options($guardian)), 422);
        $choice = ['guardian_id' => $guardian->id, 'key' => $key, 'revision' => $this->revision($guardian)];
        $request->session()->put(self::SESSION_KEY, $choice);
        // Laravel encrypts this cookie. Each account and browser keeps its own choice.
        Cookie::queue($this->cookieName($guardian), json_encode($choice), 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax');
    }

    public function cookieName(Guardian $guardian): string
    {
        return 'parent_identity_'.$guardian->id;
    }

    private function validChoice(mixed $choice, Guardian $guardian, array $options): bool
    {
        return is_array($choice)
            && ($choice['guardian_id'] ?? null) === $guardian->id
            && is_string($choice['key'] ?? null)
            && isset($options[$choice['key']])
            && ($choice['revision'] ?? null) === $this->revision($guardian);
    }

    private function revision(Guardian $guardian): string
    {
        return hash('sha256', json_encode($this->options($guardian), JSON_UNESCAPED_UNICODE));
    }
}
