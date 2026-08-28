<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_page_serves_all_main_portals(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSeeText('دخول المنصة')
            ->assertDontSeeText('حساب واحد للوصول إلى بوابة الإدارة أو ولي الأمر أو الطالب.')
            ->assertDontSeeText('أنواع بوابات المنصة');
    }

    public function test_student_login_redirects_to_student_portal(): void
    {
        $studentUser = $this->createStudentAccount();

        $this->post('/login', ['email' => $studentUser->email, 'password' => 'password123'])
            ->assertRedirect('/student/dashboard');

        $this->assertAuthenticatedAs($studentUser);
    }

    public function test_root_and_login_show_login_even_when_a_session_exists(): void
    {
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);
        $studentUser = $this->createStudentAccount();
        $adminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->get('/')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/login')->assertOk();
        $this->actingAs($studentUser)->get('/')->assertRedirect('/login');
        $this->actingAs($studentUser)->get('/login')->assertOk();
        $this->actingAs($adminUser)->get('/')->assertRedirect('/login');
        $this->actingAs($adminUser)->get('/login')->assertOk();

        $this->actingAs($parentUser)->post('/login', [
            'email' => $studentUser->email,
            'password' => 'password123',
        ])->assertRedirect('/student/dashboard');
        $this->assertAuthenticatedAs($studentUser);
    }

    public function test_student_portal_is_only_for_student_accounts(): void
    {
        $studentUser = $this->createStudentAccount();
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);

        $this->get('/student/dashboard')->assertRedirect('/login');
        $this->actingAs($parentUser)->get('/student/dashboard')->assertForbidden();
        $this->actingAs($studentUser)->get('/student/dashboard')->assertOk()->assertSeeText($studentUser->name);
    }

    private function createStudentAccount(): User
    {
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);

        $classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $studentUser = User::factory()->create([
            'name' => 'Student Account',
            'role' => 'student',
            'status' => 'active',
            'password' => 'password123',
        ]);

        Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'S-LOGIN',
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);

        return $studentUser;
    }
}
