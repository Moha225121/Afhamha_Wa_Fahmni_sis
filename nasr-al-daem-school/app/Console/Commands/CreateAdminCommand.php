<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {--name=} {--email=}';

    protected $description = 'إنشاء حساب مدير بأمان دون كلمة مرور ثابتة';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('اسم المدير');
        $email = $this->option('email') ?: $this->ask('البريد الإلكتروني');
        $password = $this->secret('كلمة المرور (8 أحرف على الأقل)');

        validator(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ])->validate();

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->info('تم إنشاء حساب المدير بنجاح.');

        return self::SUCCESS;
    }
}
