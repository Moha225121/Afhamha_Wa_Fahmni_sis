<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AccountPasswordService
{
    public const SETTING_KEY = 'new_account_password';

    public function forNewAccount(?string $password): string
    {
        if ($password !== null && $password !== '') {
            return $password;
        }

        $encrypted = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');

        // The User model hashes this separately for each new account.
        return $encrypted === null ? 'AWFN#26' : Crypt::decryptString($encrypted);
    }

    public function updateDefault(string $password): void
    {
        DB::transaction(function () use ($password): void {
            DB::table('settings')->updateOrInsert(
                ['key' => self::SETTING_KEY],
                ['value' => Crypt::encryptString($password), 'group' => 'accounts', 'updated_at' => now()],
            );

            // Record the change without storing the password or its encrypted value in the audit log.
            AuditService::record('default_password_changed', 'account_settings');
        });
    }
}
