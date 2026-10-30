<?php

namespace Tests\Feature;

use App\Http\Controllers\FcmTokenController;
use App\Models\FcmToken;
use App\Models\User;
use App\Services\ParentStudentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresFcm;
use Tests\TestCase;

class FcmTokenTest extends TestCase
{
    use ConfiguresFcm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'token' => 'test-token:'.str_repeat('a', 80),
            'device_id' => (string) Str::uuid(),
            'endpoint' => 'https://push.example.test/browser',
        ], $overrides);
    }

    public function test_both_endpoints_require_authentication(): void
    {
        $this->postJson('/fcm-tokens', $this->registration())->assertUnauthorized();
        $this->deleteJson('/fcm-tokens', ['token' => 'private-token'])->assertUnauthorized();
        $this->assertDatabaseCount('fcm_tokens', 0);
    }

    public function test_registration_uses_current_user_encrypts_token_and_is_idempotent(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $data = $this->registration();
        $this->actingAs($user)->postJson('/fcm-tokens', $data)->assertOk()->assertExactJson(['ok' => true])->assertCookie(FcmTokenController::DEVICE_COOKIE);
        $this->travel(1)->minutes();
        $this->postJson('/fcm-tokens', $data)->assertOk();
        $this->assertDatabaseCount('fcm_tokens', 1);
        $record = FcmToken::sole();
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame($data['token'], $record->token);
        $this->assertNotSame($data['token'], DB::table('fcm_tokens')->value('token'));
        $this->assertGreaterThan($record->created_at->timestamp, $record->last_seen_at->timestamp);
        $this->assertArrayNotHasKey('token', $record->toArray());
    }

    public function test_client_supplied_user_and_invalid_input_are_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user)->postJson('/fcm-tokens', $this->registration(['user_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        foreach ([['token' => ''], ['token' => 'invalid token with whitespace'], ['token' => str_repeat('a', 4097)], ['device_id' => 'invalid'], ['endpoint' => 'http://insecure.test/push'], ['legacy_endpoints' => array_fill(0, 6, 'https://push.example.test/x')]] as $invalid) {
            $this->postJson('/fcm-tokens', $this->registration($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('fcm_tokens', 0);
    }

    public function test_rotation_and_account_change_leave_one_current_browser_owner(): void
    {
        $old = User::factory()->create(['status' => 'active']);
        $new = User::factory()->create(['status' => 'active']);
        $data = $this->registration();
        $this->actingAs($old)->postJson('/fcm-tokens', $data)->assertOk();
        $data['token'] = 'rotated:'.str_repeat('b', 80);
        $this->postJson('/fcm-tokens', $data)->assertOk();
        $this->actingAs($new)->postJson('/fcm-tokens', $data)->assertOk();
        $this->assertDatabaseCount('fcm_tokens', 1);
        $this->assertSame($new->id, FcmToken::sole()->user_id);
        $this->assertSame($data['token'], FcmToken::sole()->token);
    }

    public function test_existing_subscriptions_from_both_worker_scopes_are_associated_for_deduplication(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        foreach (['root', 'parent'] as $scope) {
            $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/'.$scope, 'public_key' => 'key', 'auth_token' => 'auth']);
        }
        $other->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/root', 'public_key' => 'key', 'auth_token' => 'auth']);
        $otherDevice = $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/other-device', 'public_key' => 'key', 'auth_token' => 'auth']);
        $data = $this->registration(['legacy_endpoints' => ['https://push.example.test/root', 'https://push.example.test/parent']]);
        $this->actingAs($user)->postJson('/fcm-tokens', $data)->assertOk();
        $this->assertSame(2, $user->pushSubscriptions()->where('device_id', $data['device_id'])->count());
        $this->assertNull($otherDevice->fresh()->device_id);
        $this->assertSame(0, $other->pushSubscriptions()->count());
    }

    public function test_removal_is_scoped_to_owner_and_current_device(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $own = $this->tokenFor($user, 'own-device-token');
        $second = $this->tokenFor($user, 'second-device-token');
        $foreign = $this->tokenFor($other, 'foreign-device-token');
        $this->actingAs($user)->deleteJson('/fcm-tokens', ['token' => $foreign->token])->assertOk();
        $this->assertModelExists($foreign);
        $this->deleteJson('/fcm-tokens', ['token' => $own->token])->assertOk();
        $this->assertModelMissing($own);
        $this->assertModelExists($second);
    }

    public function test_inactive_and_parent_student_sessions_cannot_register(): void
    {
        $inactive = User::factory()->create(['status' => 'inactive']);
        $this->actingAs($inactive)->postJson('/fcm-tokens', $this->registration())->assertForbidden();
        $active = User::factory()->create(['status' => 'active']);
        $this->actingAs($active)->withSession([ParentStudentSession::PARENT_ID => 123])->postJson('/fcm-tokens', $this->registration())->assertForbidden();
    }

    public function test_registration_and_removal_are_rate_limited(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $data = $this->registration();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/fcm-tokens', $data)->assertOk();
        }
        $this->postJson('/fcm-tokens', $data)->assertTooManyRequests();
        $this->travel(61)->seconds();
        for ($i = 0; $i < 20; $i++) {
            $this->deleteJson('/fcm-tokens', ['token' => $data['token']])->assertOk();
        }
        $this->deleteJson('/fcm-tokens', ['token' => $data['token']])->assertTooManyRequests();
    }

    public function test_logout_removes_this_device_but_keeps_other_devices(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $own = $this->tokenFor($user, 'current-browser-token');
        $other = $this->tokenFor($user, 'other-browser-token');
        $legacy = $user->pushSubscriptions()->create(['device_id' => $own->device_id, 'endpoint' => 'https://push.example.test/current', 'public_key' => 'key', 'auth_token' => 'auth']);
        $this->actingAs($user)->withCookie(FcmTokenController::DEVICE_COOKIE, $own->device_id)->post('/logout')->assertRedirect('/login');
        $this->assertModelMissing($own);
        $this->assertModelMissing($legacy);
        $this->assertModelExists($other);
    }

    public function test_missing_configuration_is_graceful_and_no_private_values_reach_worker_or_view(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        config()->set('firebase.projects.app.credentials', 'PRIVATE-SERVICE-ACCOUNT-PATH');
        $this->actingAs($user);
        $html = view('shared.push-config')->render();
        $this->assertStringContainsString('public-test-key', $html);
        $this->assertStringNotContainsString('PRIVATE-SERVICE-ACCOUNT-PATH', $html);
        $this->get('/push/firebase-worker.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')->assertDontSee('PRIVATE-SERVICE-ACCOUNT-PATH');
        config()->set('fcm.enabled', false);
        $this->postJson('/fcm-tokens', $this->registration())->assertStatus(503);
        $this->get('/push/firebase-worker.js')->assertOk()->assertDontSee('public-test-key');
        $this->assertDatabaseCount('fcm_tokens', 0);
    }

    public function test_migration_rolls_back_without_removing_legacy_subscriptions(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/preserved', 'public_key' => 'key', 'auth_token' => 'auth']);
        $migration = require database_path('migrations/2026_10_18_000002_create_fcm_tokens.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('fcm_tokens'));
        $this->assertFalse(Schema::hasColumn('push_subscriptions', 'device_id'));
        $this->assertDatabaseCount('push_subscriptions', 1);
        $migration->up();
        $this->assertTrue(Schema::hasTable('fcm_tokens'));
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_activation_feedback_script_is_available_even_when_push_configuration_is_missing(): void
    {
        config()->set('fcm.enabled', false);
        config()->set('services.web_push.public_key', null);
        $html = view('shared.push-subscription')->render();
        $this->assertStringContainsString('data-push-enable', $html);
        $this->assertStringContainsString('js/push-activation-status.js', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('الإشعارات الفورية غير متاحة', $html);
    }
}
