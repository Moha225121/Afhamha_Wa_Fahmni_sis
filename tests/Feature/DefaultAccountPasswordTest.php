<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use App\Services\AccountPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DefaultAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function payload(string $role): array
    {
        $data = ['name' => 'حساب جديد', 'email' => $role.'@example.test', 'phone' => '0912345678', 'status' => 'active'];
        if ($role === 'student') {
            $data['student_number'] = 'ST-001';
        }
        if ($role === 'supervisor') {
            $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
            $classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
            $data['classroom_ids'] = [$classroom->id];
        }

        return $data;
    }

    public function test_new_accounts_use_the_fixed_password_and_can_log_in(): void
    {
        $admin = $this->admin();
        $hashes = [];
        foreach (['student' => 'students', 'teacher' => 'teachers', 'parent' => 'parents', 'supervisor' => 'supervisors'] as $role => $resource) {
            $payload = $this->payload($role);
            // Browsers submit empty password fields; APIs may omit them entirely.
            if ($role === 'student') {
                $payload += ['password' => '', 'password_confirmation' => ''];
            }
            $this->actingAs($admin)->post('/admin/'.$resource, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $user = User::where('email', $payload['email'])->firstOrFail();
            $this->assertTrue(Hash::check('AWFN#26', $user->password));
            $hashes[] = $user->password;
            auth()->logout();
            $this->post('/login', ['email' => $user->email, 'password' => 'AWFN#26'])->assertRedirect('/'.$role.'/dashboard');
            $this->assertAuthenticatedAs($user);
        }
        $this->assertCount(4, array_unique($hashes));
    }

    public function test_admin_can_change_default_only_for_future_accounts(): void
    {
        $admin = $this->admin();
        $payload = $this->payload('student');
        $this->actingAs($admin)->post('/admin/students', $payload)->assertSessionHasNoErrors();
        $existing = User::where('email', $payload['email'])->firstOrFail();
        $previousHash = $existing->password;

        $this->put(route('admin.settings.account-password.update'), [
            'password' => 'AWFN#27', 'password_confirmation' => 'AWFN#27',
        ])->assertRedirect(route('admin.settings.index'))->assertSessionHasNoErrors();
        $encrypted = DB::table('settings')->where('key', AccountPasswordService::SETTING_KEY)->value('value');
        $this->assertNotSame('AWFN#27', $encrypted);
        $this->assertSame('AWFN#27', Crypt::decryptString($encrypted));
        $this->assertSame($previousHash, $existing->fresh()->password);

        $this->post('/admin/teachers', $this->payload('teacher'))->assertSessionHasNoErrors();
        $newUser = User::where('email', 'teacher@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('AWFN#27', $newUser->password));
        $this->assertFalse(Hash::check('AWFN#26', $newUser->password));
        $audit = AuditLog::where('action', 'default_password_changed')->sole();
        $this->assertEquals($admin->id, $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);

        // Leaving a password blank during editing must not reset it to the new default.
        $this->put(route('admin.students.update', $existing->student), $payload + ['password' => '', 'password_confirmation' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame($previousHash, $existing->fresh()->password);
        auth()->logout();
        $this->post('/login', ['email' => $newUser->email, 'password' => 'AWFN#27'])->assertRedirect('/teacher/dashboard');
        $this->assertAuthenticatedAs($newUser);
    }

    public function test_explicit_account_passwords_remain_supported_and_confirmed(): void
    {
        $payload = $this->payload('teacher');
        $this->actingAs($this->admin())->post('/admin/teachers', $payload + ['password' => 'custom-password', 'password_confirmation' => 'incorrect'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->post('/admin/teachers', $payload + ['password' => 'custom-password', 'password_confirmation' => 'custom-password'])
            ->assertSessionHasNoErrors();
        $user = User::where('email', $payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check('custom-password', $user->password));
        $this->assertFalse(Hash::check('AWFN#26', $user->password));
    }

    public function test_only_active_admins_can_change_the_default(): void
    {
        $payload = ['password' => 'Changed#26', 'password_confirmation' => 'Changed#26'];
        $url = route('admin.settings.account-password.update');
        $this->put($url, $payload)->assertRedirect('/login');
        foreach (['student', 'teacher', 'parent', 'supervisor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->put($url, $payload)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'inactive']))->put($url, $payload)->assertForbidden();
        $this->assertSame('AWFN#26', app(AccountPasswordService::class)->forNewAccount(null));
    }

    public function test_invalid_default_does_not_replace_setting_or_flash_password(): void
    {
        $this->actingAs($this->admin());
        foreach ([['', ''], ['short', 'short'], ['Changed#26', 'different'], [str_repeat('ع', 40), str_repeat('ع', 40)]] as [$password, $confirmation]) {
            $this->from(route('admin.settings.index'))->put(route('admin.settings.account-password.update'), [
                'password' => $password, 'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors('password')
                ->assertSessionMissing('_old_input.password')
                ->assertSessionMissing('_old_input.password_confirmation');
        }
        $this->assertSame('AWFN#26', app(AccountPasswordService::class)->forNewAccount(null));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'default_password_changed']);
    }

    public function test_forms_render_without_exposing_the_default_and_audit_does_not_leak_hashes(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/settings')->assertOk()->assertSeeText('كلمة المرور الافتراضية للحسابات الجديدة')->assertDontSee('AWFN#26');
        foreach (['students', 'teachers', 'parents', 'supervisors'] as $resource) {
            $this->get('/admin/'.$resource.'/create')->assertOk()->assertSeeText('اختياري: اتركها فارغة')->assertDontSee('AWFN#26');
        }
        $this->post('/admin/supervisors', $this->payload('supervisor'))->assertSessionHasNoErrors();
        $audit = AuditLog::where('module', 'supervisors')->sole();
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertArrayNotHasKey('remember_token', $audit->new_values);
    }
}
