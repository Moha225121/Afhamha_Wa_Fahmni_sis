<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nasbia.parent_student_session' => true]);
    }

    private function guardian(bool $additional = true): Guardian
    {
        $user = User::factory()->create([
            'name' => 'أحمد الأب', 'phone' => '0910000001', 'role' => 'parent',
            'status' => 'active', 'password' => 'password123', 'last_login_at' => now()->subDays(5),
        ]);

        return $user->guardian()->create([
            'relationship' => 'الأب', 'status' => 'active',
            'additional_guardian_name' => $additional ? 'فاطمة الأم' : null,
            'additional_guardian_phone' => $additional ? '0920000002' : null,
            'additional_guardian_relationship' => $additional ? 'الأم' : null,
        ])->load('user');
    }

    private function payload(): array
    {
        return [
            'name' => 'أحمد الأب', 'email' => 'new-parent@example.test', 'phone' => '0910000001',
            'relationship' => 'الأب', 'status' => 'active', 'additional_guardian_enabled' => '1',
            'additional_guardian_name' => 'فاطمة الأم', 'additional_guardian_phone' => '0920000002',
            'additional_guardian_relationship' => 'الأم',
        ];
    }

    public function test_admin_can_create_and_edit_two_people_in_one_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/admin/parents/create')->assertOk()->assertSeeText('إسناد ولي أمر آخر');
        $this->post('/admin/parents', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $guardian = User::where('email', 'new-parent@example.test')->firstOrFail()->guardian;
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'additional_guardian_name' => 'فاطمة الأم', 'additional_guardian_phone' => '0920000002', 'additional_guardian_relationship' => 'الأم']);
        $this->assertSame(1, User::where('role', 'parent')->count());
        $this->get('/admin/parents/'.$guardian->id.'/edit')->assertOk()->assertSee('فاطمة الأم')->assertSee('0920000002')->assertSee('الأم');
        $this->get('/admin/parents/'.$guardian->id)->assertOk()->assertSeeText('فاطمة الأم');

        $payload = array_replace($this->payload(), ['additional_guardian_name' => 'مريم الخالة', 'additional_guardian_relationship' => 'الخالة']);
        $this->put('/admin/parents/'.$guardian->id, $payload)->assertSessionHasNoErrors();
        $this->assertSame('مريم الخالة', $guardian->fresh()->additional_guardian_name);
        $this->put('/admin/parents/'.$guardian->id, array_replace($payload, ['additional_guardian_enabled' => '0']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('guardians', ['id' => $guardian->id, 'additional_guardian_name' => null, 'additional_guardian_phone' => null, 'additional_guardian_relationship' => null]);
    }

    public function test_assignment_requires_all_three_details_and_restores_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        foreach (['name', 'phone', 'relationship'] as $field) {
            $key = 'additional_guardian_'.$field;
            $this->actingAs($admin)->from('/admin/parents/create')->post('/admin/parents', array_replace($this->payload(), [$key => '']))
                ->assertSessionHasErrors($key)->assertSessionHasInput('additional_guardian_enabled', '1');
        }
        $this->assertDatabaseMissing('users', ['email' => 'new-parent@example.test']);
    }

    public function test_old_accounts_are_prompted_on_login_even_when_they_logged_in_before(): void
    {
        $guardian = $this->guardian();
        $this->post('/login', ['email' => $guardian->user->email, 'password' => 'password123'])->assertRedirect('/parent/who-is-using');
        $this->get('/parent/who-is-using')->assertOk()->assertSeeText('من سيستخدم المنصة؟')
            ->assertSeeText('أحمد الأب')->assertSeeText('فاطمة الأم')->assertSeeText('الأم')->assertSee('0920000002');
    }

    public function test_existing_sessions_cannot_bypass_selection_on_any_portal_route(): void
    {
        $guardian = $this->guardian();
        foreach (['dashboard', 'children', 'profile', 'notifications', 'results'] as $page) {
            $this->actingAs($guardian->user)->get('/parent/'.$page)->assertRedirect('/parent/who-is-using');
        }
        $this->put('/parent/profile', ['name' => 'Unchosen', 'phone' => '123'])->assertRedirect('/parent/who-is-using');
        $this->assertSame('أحمد الأب', $guardian->user->fresh()->name);
    }

    public function test_selected_person_controls_greeting_and_profile_with_shared_child_permissions(): void
    {
        $guardian = $this->guardian();
        $child = Student::create(['user_id' => User::factory()->create(['name' => 'ابن مرتبط'])->id, 'student_number' => 'LINKED-1', 'status' => 'active']);
        $other = Student::create(['user_id' => User::factory()->create(['name' => 'ابن غير مرتبط'])->id, 'student_number' => 'OTHER-1', 'status' => 'active']);
        $guardian->students()->attach($child);
        $this->actingAs($guardian->user)->post('/parent/who-is-using', ['identity' => 'additional'])->assertRedirect('/parent/dashboard');
        $this->get('/parent/dashboard')->assertOk()->assertSeeText('مرحباً فاطمة')->assertSeeText('تغيير مستخدم المنصة');
        $this->get('/parent/profile')->assertOk()->assertSee('فاطمة الأم')->assertSee('0920000002');
        $this->get('/parent/children')->assertOk()->assertSeeText('ابن مرتبط')->assertDontSeeText('ابن غير مرتبط');
        $this->get('/parent/children/'.$other->id)->assertNotFound();
        $this->put('/parent/profile', ['name' => 'فاطمة المعدل', 'phone' => '0921111111'])->assertSessionHasNoErrors()->assertRedirect('/parent/dashboard');
        $this->assertSame('أحمد الأب', $guardian->user->fresh()->name);
        $this->assertSame('0910000001', $guardian->user->fresh()->phone);
        $this->assertSame('فاطمة المعدل', $guardian->fresh()->additional_guardian_name);
        $this->get('/parent/dashboard')->assertOk()->assertSeeText('فاطمة المعدل');
        $this->post('/parent/who-is-using', ['identity' => 'primary'])->assertRedirect('/parent/dashboard');
        $this->get('/parent/profile')->assertOk()->assertSee('أحمد الأب')->assertSee('0910000001');
    }

    public function test_choice_is_remembered_for_this_browser_and_account(): void
    {
        $guardian = $this->guardian();
        $identities = app(ParentIdentityService::class);
        $response = $this->actingAs($guardian->user)->post('/parent/who-is-using', ['identity' => 'additional']);
        $cookieName = $identities->cookieName($guardian);
        $response->assertCookie($cookieName);
        $cookie = $response->getCookie($cookieName)->getValue();
        $this->post('/logout')->assertRedirect('/login');
        $this->withCookie($cookieName, $cookie)->post('/login', ['email' => $guardian->user->email, 'password' => 'password123'])->assertRedirect('/parent/dashboard');
        $this->get('/parent/profile')->assertOk()->assertSee('فاطمة الأم');

        $other = $this->guardian();
        $this->post('/logout');
        $this->post('/login', ['email' => $other->user->email, 'password' => 'password123'])->assertRedirect('/parent/who-is-using');
    }

    public function test_selection_is_reset_when_admin_changes_or_adds_the_other_person(): void
    {
        $guardian = $this->guardian(false);
        $this->actingAs($guardian->user)->get('/parent/dashboard')->assertOk();
        $guardian->update(['additional_guardian_name' => 'فاطمة الأم', 'additional_guardian_phone' => '0920000002', 'additional_guardian_relationship' => 'الأم']);
        $guardian->user->unsetRelation('guardian');
        $this->get('/parent/dashboard')->assertRedirect('/parent/who-is-using');
        $this->post('/parent/who-is-using', ['identity' => 'additional']);
        $guardian->update(['additional_guardian_name' => 'مريم الخالة']);
        $guardian->user->unsetRelation('guardian');
        $this->get('/parent/dashboard')->assertRedirect('/parent/who-is-using');
    }

    public function test_removed_assignment_returns_to_primary_and_single_name_skips_prompt(): void
    {
        $guardian = $this->guardian();
        $this->actingAs($guardian->user)->post('/parent/who-is-using', ['identity' => 'additional']);
        $guardian->update(['additional_guardian_name' => null, 'additional_guardian_phone' => null, 'additional_guardian_relationship' => null]);
        $guardian->user->unsetRelation('guardian');
        $this->get('/parent/dashboard')->assertOk()->assertSeeText('مرحباً أحمد');
        $this->get('/parent/who-is-using')->assertRedirect('/parent/dashboard');
        $this->post('/parent/who-is-using', ['identity' => 'additional'])->assertSessionHasErrors('identity');
    }

    public function test_invalid_or_foreign_choices_and_inactive_accounts_are_rejected(): void
    {
        $guardian = $this->guardian();
        $other = $this->guardian();
        $this->actingAs($guardian->user)->post('/parent/who-is-using', ['identity' => (string) $other->id])->assertSessionHasErrors('identity');
        $this->post('/parent/who-is-using', ['identity' => ['additional']])->assertSessionHasErrors('identity');
        $this->withSession([ParentIdentityService::SESSION_KEY => ['guardian_id' => $other->id, 'key' => 'additional', 'revision' => 'fake']])
            ->get('/parent/dashboard')->assertRedirect('/parent/who-is-using');
        $guardian->update(['status' => 'inactive']);
        $guardian->user->unsetRelation('guardian');
        $this->get('/parent/who-is-using')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'student']))->get('/parent/who-is-using')->assertForbidden();
    }
}
