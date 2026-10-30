<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\WebPushService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class AdminNotificationTest extends TestCase
{
    use ConfiguresFcm, RefreshDatabase;
    use CreatesStudentEducationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
        config(['services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function payload(User $recipient, array $overrides = []): array
    {
        return array_replace([
            'recipient_id' => $recipient->id, 'title' => 'School reminder',
            'message' => 'Your appointment is tomorrow.', 'submission_id' => (string) Str::uuid(),
        ], $overrides);
    }

    public function test_guests_non_admin_roles_and_inactive_administrators_cannot_compose_or_send(): void
    {
        $recipient = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->mock(ParentNotificationService::class)->shouldNotReceive('send');
        $this->get('/admin/notifications/create')->assertRedirect('/login');
        $this->postJson('/admin/notifications', $this->payload($recipient))->assertUnauthorized();

        foreach (['student', 'parent', 'teacher', 'supervisor', 'staff', 'financial_officer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/admin/notifications/create')->assertForbidden();
            $this->postJson('/admin/notifications', $this->payload($recipient))->assertForbidden();
        }
        $inactive = User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        $this->actingAs($inactive)->get('/admin/notifications/create')->assertForbidden();
        $this->postJson('/admin/notifications', $this->payload($recipient))->assertForbidden();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_composer_is_linked_from_admin_navigation_and_inbox_and_never_exposes_device_secrets(): void
    {
        $recipient = User::factory()->create(['name' => '<script>alert(1)</script>', 'status' => 'active']);
        $inactive = User::factory()->create(['name' => 'Disabled recipient', 'status' => 'inactive']);
        $this->tokenFor($recipient, 'private-fake-device-token');
        $this->actingAs($this->admin())->get('/admin/notifications/create?recipient_id='.$recipient->id)
            ->assertOk()->assertSee('action="'.route('admin.notifications.store').'"', false)
            ->assertSee('name="_token"', false)->assertSee('name="submission_id"', false)
            ->assertSee(e($recipient->name), false)->assertDontSee($recipient->name, false)
            ->assertDontSee($inactive->name)->assertDontSee('private-fake-device-token');
        $this->get('/admin/notifications')->assertOk()->assertSee(route('admin.notifications.create'), false);
    }

    public function test_recipient_search_filters_active_users_and_preserves_a_selected_user(): void
    {
        $selected = User::factory()->create(['name' => 'Selected account', 'status' => 'active']);
        $match = User::factory()->create(['name' => 'School recipient', 'status' => 'active', 'email' => 'findme@example.test']);
        $other = User::factory()->create(['name' => 'Unrelated account', 'status' => 'active']);
        $this->actingAs($this->admin())->get('/admin/notifications/create?q=findme&recipient_id='.$selected->id)
            ->assertOk()->assertSee($match->email)->assertSee($selected->name)->assertDontSee($other->name);
    }

    public function test_admin_send_targets_one_user_creates_one_database_notification_and_reports_fcm_acceptance(): void
    {
        $admin = $this->admin();
        $recipient = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $token = $this->tokenFor($recipient, 'selected-user-device-token');
        $this->tokenFor($other, 'another-user-device-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->withArgs(function ($message, $tokens) use ($recipient): bool {
            $data = $message->jsonSerialize()['data'];

            return $tokens === ['selected-user-device-token'] && $data['recipient_id'] === (string) $recipient->id
                && $data['title'] === 'School reminder' && $data['body'] === 'Your appointment is tomorrow.'
                && $data['url'] === '/student/notifications' && $data['category'] === 'admin_notification';
        })->andReturn(MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with('token', $token->token), ['name' => 'accepted']),
        ]));

        $this->actingAs($admin)->post('/admin/notifications', $this->payload($recipient, [
            'url' => 'https://untrusted.example', 'admin_id' => $other->id,
        ]))->assertRedirect(route('admin.notifications.create'))->assertSessionHas('notification_result.status', 'success');
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->assertSame('/student/notifications', $recipient->notifications()->sole()->data['url']);
        $audit = AuditLog::where('module', 'manual_notifications')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($recipient->id, $audit->record_id);
        $this->assertStringNotContainsString('selected-user-device-token', json_encode($audit->new_values));
        $this->get('/admin/notifications/create')->assertOk()->assertSee('قبول الإرسال لا يؤكد ظهور الإشعار على الشاشة.');
    }

    public function test_invalid_recipient_and_oversize_or_missing_fields_never_dispatch(): void
    {
        $admin = $this->admin();
        $recipient = User::factory()->create(['status' => 'active']);
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->mock(ParentNotificationService::class)->shouldNotReceive('send');
        $this->actingAs($admin);
        foreach ([
            ['recipient_id' => $inactive->id], ['recipient_id' => 999999], ['recipient_id' => [$recipient->id]],
            ['title' => ''], ['title' => str_repeat('ع', 61)], ['message' => ''],
            ['message' => str_repeat('🙂', 301)], ['submission_id' => 'invalid'],
        ] as $invalid) {
            // Validation attempts share the same abuse limit as successful sends.
            $this->postJson('/admin/notifications', $this->payload($recipient, $invalid))->assertUnprocessable();
            $this->travel(61)->seconds();
        }
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_duplicate_form_submission_never_sends_twice(): void
    {
        $recipient = User::factory()->create(['status' => 'active']);
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $payload = $this->payload($recipient);
        $this->actingAs($this->admin())->post('/admin/notifications', $payload)->assertRedirect();
        $this->post('/admin/notifications', $payload)->assertRedirect()->assertSessionHas('notification_result.status', 'warning');
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame(1, AuditLog::where('module', 'manual_notifications')->count());
    }

    public function test_malformed_form_values_render_validation_errors_without_breaking_the_composer(): void
    {
        $recipient = User::factory()->create(['status' => 'active']);
        $this->mock(ParentNotificationService::class)->shouldNotReceive('send');
        $this->actingAs($this->admin())->from('/admin/notifications/create')
            ->post('/admin/notifications', $this->payload($recipient, [
                'recipient_id' => [$recipient->id], 'title' => ['invalid'],
                'message' => ['invalid'], 'submission_id' => ['invalid'],
            ]))->assertRedirect('/admin/notifications/create')
            ->assertSessionHasErrors(['recipient_id', 'title', 'message', 'submission_id']);
        $this->get('/admin/notifications/create')->assertOk();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_rate_limits_apply_per_admin_for_both_minute_and_hour(): void
    {
        $recipient = User::factory()->create(['status' => 'active']);
        $payload = $this->payload($recipient);
        $this->actingAs($this->admin());
        for ($batch = 0; $batch < 6; $batch++) {
            for ($i = 0; $i < 5; $i++) {
                $this->post('/admin/notifications', $payload)->assertRedirect();
            }
            $this->post('/admin/notifications', $payload)->assertTooManyRequests();
            $this->travel(61)->seconds();
        }
        $this->post('/admin/notifications', $payload)->assertTooManyRequests();
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($recipient))->assertRedirect();
        $this->assertSame(2, $recipient->notifications()->count());
    }

    public function test_push_failure_preserves_database_notification_and_reports_no_confirmed_push_without_leaking_secrets(): void
    {
        $recipient = User::factory()->create(['status' => 'active']);
        $this->tokenFor($recipient, 'sensitive-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()
            ->andThrow(new RuntimeException('SERVICE-ACCOUNT-KEY sensitive-token'));
        Log::spy();
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($recipient))
            ->assertRedirect()->assertSessionHas('notification_result.status', 'warning');
        $this->assertSame(1, $recipient->notifications()->count());
        $this->get('/admin/notifications/create')->assertOk()->assertSee('لم يُقبل أي إرسال')
            ->assertDontSee('SERVICE-ACCOUNT-KEY')->assertDontSee('sensitive-token');
        Log::shouldHaveReceived('warning')->once()->with('FCM is unavailable; legacy push remains available.', ['user_id' => $recipient->id]);
    }

    public function test_legacy_acceptance_is_reported_when_fcm_is_disabled(): void
    {
        config(['fcm.enabled' => false]);
        $recipient = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        $this->mock(WebPushService::class)->shouldReceive('send')->once()->andReturn(1);
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($recipient))
            ->assertRedirect()->assertSessionHas('notification_result.status', 'success');
        $this->assertSame('/parent/notifications', $recipient->notifications()->sole()->data['url']);
    }

    public function test_dispatch_exception_has_a_sanitized_failure_result(): void
    {
        $recipient = User::factory()->create(['status' => 'active']);
        $this->mock(ParentNotificationService::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('PRIVATE-DATA'));
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($recipient))
            ->assertRedirect()->assertSessionHas('notification_result.status', 'error');
        $this->get('/admin/notifications/create')->assertOk()->assertSee('تعذّر تأكيد إرسال الإشعار')->assertDontSee('PRIVATE-DATA');
    }

    public function test_post_requires_a_valid_csrf_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $recipient = User::factory()->create(['status' => 'active']);
        $this->mock(ParentNotificationService::class)->shouldNotReceive('send');
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($recipient))->assertStatus(419);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_manual_notification_is_visible_in_the_existing_student_feed(): void
    {
        $student = $this->createStudentFixture('manual-admin');
        $this->actingAs($this->admin())->post('/admin/notifications', $this->payload($student['user']))->assertRedirect();
        $this->actingAs($student['user'])->getJson('/student/notifications/summary')
            ->assertOk()->assertJsonPath('notifications.0.title', 'School reminder')
            ->assertJsonPath('notifications.0.body', 'Your appointment is tomorrow.')
            ->assertJsonPath('notifications.0.url', '/student/notifications');
    }
}
