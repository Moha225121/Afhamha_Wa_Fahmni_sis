<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $parentUser;

    private Guardian $guardian;

    private Student $linkedStudent;

    private Student $secondLinkedStudent;

    private Student $unlinkedStudent;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);

        $this->classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $this->parentUser = User::factory()->create([
            'name' => 'Parent Account',
            'email' => 'parent@example.test',
            'role' => 'parent',
            'status' => 'active',
            'password' => 'password123',
        ]);

        $this->guardian = Guardian::create([
            'user_id' => $this->parentUser->id,
            'relationship' => 'father',
            'status' => 'active',
        ]);

        $this->linkedStudent = $this->student('Linked Student', 'S-101');
        $this->secondLinkedStudent = $this->student('Second Linked', 'S-102');
        $this->unlinkedStudent = $this->student('Other Student', 'S-999');
        $this->guardian->students()->attach([$this->linkedStudent->id, $this->secondLinkedStudent->id]);
    }

    public function test_parent_login_uses_main_auth_and_redirects_to_parent_portal(): void
    {
        $this->post('/login', ['email' => 'parent@example.test', 'password' => 'password123'])
            ->assertRedirect('/parent/dashboard');

        $this->assertAuthenticatedAs($this->parentUser);
    }

    public function test_parent_sees_only_students_linked_to_their_guardian_record(): void
    {
        $this->actingAs($this->parentUser)
            ->get('/parent/children')
            ->assertOk()
            ->assertSeeText('Linked Student')
            ->assertSeeText('Second Linked')
            ->assertDontSeeText('Other Student');
    }

    public function test_parent_cannot_open_unlinked_student_by_changing_the_url(): void
    {
        $this->actingAs($this->parentUser)
            ->get('/parent/children/'.$this->unlinkedStudent->id)
            ->assertNotFound();
    }

    public function test_parent_can_switch_between_linked_students(): void
    {
        $subject = Subject::create(['name' => 'Math', 'code' => 'MATH-1', 'stage' => 'Primary', 'status' => 'active']);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacherId = DB::table('teachers')->insertGetId(['user_id' => $teacherUser->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $examId = DB::table('exams')->insertGetId([
            'title' => 'Unit Exam',
            'subject_id' => $subject->id,
            'classroom_id' => $this->classroom->id,
            'teacher_id' => $teacherId,
            'starts_at' => now(),
            'duration_minutes' => 45,
            'total_score' => 20,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('grades')->insert([
            'exam_id' => $examId,
            'student_id' => $this->secondLinkedStudent->id,
            'score' => 18,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->parentUser)
            ->get('/parent/results?student='.$this->secondLinkedStudent->id)
            ->assertOk()
            ->assertSeeText('Second Linked')
            ->assertSeeText('18 / 20');
    }

    public function test_unlinked_student_cannot_be_selected_in_results(): void
    {
        $this->actingAs($this->parentUser)
            ->get('/parent/results?student='.$this->unlinkedStudent->id)
            ->assertNotFound();
    }

    public function test_parent_profile_update_persists_on_main_user_record(): void
    {
        $this->actingAs($this->parentUser)
            ->put('/parent/profile', ['name' => 'Updated Parent', 'phone' => '0911111111'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $this->parentUser->id,
            'name' => 'Updated Parent',
            'phone' => '0911111111',
        ]);
    }

    public function test_parent_pwa_assets_are_available_without_sensitive_page_caching(): void
    {
        $this->get('/parent-manifest.webmanifest')
            ->assertOk()
            ->assertSee('"display": "standalone"', false)
            ->assertSee('/parent/dashboard', false);

        $this->get('/parent-sw.js')
            ->assertOk()
            ->assertSee('STATIC_ASSETS', false)
            ->assertSee('/parent-offline.html', false)
            ->assertDontSee('/parent/dashboard', false);

        $this->get('/parent-offline.html')
            ->assertOk()
            ->assertSeeText('أنت غير متصل');
    }

    private function student(string $name, string $number): Student
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'student',
            'status' => 'active',
        ]);

        return Student::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'classroom_id' => $this->classroom->id,
            'status' => 'active',
        ]);
    }
}
