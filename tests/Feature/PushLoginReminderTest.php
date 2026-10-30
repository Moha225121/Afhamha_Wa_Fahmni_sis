<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ParentStudentSession;
use App\Services\PushLoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushLoginReminderTest extends TestCase
{
    use RefreshDatabase;

    private function publicConfig(): array
    {
        $html = view('shared.push-config')->render();
        preg_match('/id="school-push-config">(.*?)<\/script>/s', $html, $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_login_reminder_nonce_survives_refresh_and_changes_after_a_new_login(): void
    {
        $password = Str::random(24);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => $password]);
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect(route('admin.dashboard'));
        $first = $this->publicConfig();
        $this->assertTrue(Str::isUuid($first['loginId']));
        $this->assertNotSame(session()->getId(), $first['loginId']);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame($first['loginId'], $this->publicConfig()['loginId']);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect(route('admin.dashboard'));
        $this->assertNotSame($first['loginId'], $this->publicConfig()['loginId']);
    }

    public function test_resumed_authentication_gets_a_stable_user_scoped_nonce_without_revealing_session_secrets(): void
    {
        $first = User::factory()->create(['status' => 'active']);
        $second = User::factory()->create(['status' => 'active']);
        $this->actingAs($first);
        $id = app(PushLoginSession::class)->id(request());
        session()->regenerate();
        $this->assertSame($id, app(PushLoginSession::class)->id(request()));
        $this->assertNotSame(session()->getId(), $id);
        $this->actingAs($second);
        $this->assertNotSame($id, app(PushLoginSession::class)->id(request()));
    }

    public function test_loader_and_login_reminder_config_are_present_even_when_fcm_and_legacy_config_are_missing(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'active']));
        config()->set('fcm.enabled', false);
        config()->set('services.web_push.public_key', null);
        config()->set('firebase.projects.app.credentials', 'PRIVATE-ACCOUNT-MUST-NOT-LEAK');
        $config = $this->publicConfig();
        $this->assertNull($config['firebase']);
        $this->assertNull($config['legacyVapidKey']);
        $html = view('shared.push-config')->render();
        $this->assertStringContainsString('js/push-activation-status.js', $html);
        $this->assertStringContainsString('type="module"', $html);
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT-MUST-NOT-LEAK', $html);
        $this->assertStringNotContainsString(session()->getId(), $html);
        $this->assertArrayNotHasKey('sessionId', $config);
    }

    public function test_guests_and_parent_student_sessions_do_not_receive_device_registration_or_reminder_configuration(): void
    {
        $this->assertStringNotContainsString('school-push-config', view('shared.push-config')->render());
        $this->actingAs(User::factory()->create(['role' => 'student', 'status' => 'active']))
            ->withSession([ParentStudentSession::PARENT_ID => 123]);
        $html = view('shared.push-config')->render();
        $this->assertStringNotContainsString('school-push-config', $html);
        $this->assertStringNotContainsString('push-activation-status.js', $html);
    }
}
