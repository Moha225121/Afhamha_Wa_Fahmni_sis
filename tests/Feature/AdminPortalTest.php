<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_admin_can_login(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'password' => 'password123',
        ]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_only_active_admin_can_access_admin_portal(): void
    {
        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
    }

    public function test_admin_can_create_student_with_real_user_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $class = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $response = $this->actingAs($admin)->post('/admin/students', ['name' => 'طالب جديد', 'email' => 'student@example.test', 'student_number' => 'S-100', 'classroom_id' => $class->id, 'status' => 'active', 'password' => 'password123', 'password_confirmation' => 'password123']);
        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'student@example.test', 'role' => 'student']);
        $this->assertDatabaseHas('students', ['student_number' => 'S-100', 'classroom_id' => $class->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'module' => 'students']);
    }
}
