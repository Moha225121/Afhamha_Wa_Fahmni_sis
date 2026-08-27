<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
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
            ->assertSee("addEventListener('push'", false)
            ->assertDontSee('/parent/dashboard', false);

        $this->get('/parent-offline.html')
            ->assertOk()
            ->assertSeeText('أنت غير متصل');
    }

    public function test_parent_can_follow_linked_child_attendance_assignments_and_exams_only(): void
    {
        $subject = Subject::create(['name' => 'Math', 'code' => 'MATH-2', 'stage' => 'Primary', 'status' => 'active']);
        $teacher = $this->teacherForClassroom($this->classroom, $subject);
        DB::table('attendance_records')->insert([
            'student_id' => $this->linkedStudent->id,
            'classroom_id' => $this->classroom->id,
            'date' => '2026-08-23',
            'status' => 'present',
            'recorded_by' => $teacher->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignment = Assignment::create([
            'classroom_id' => $this->classroom->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'title' => 'Homework One',
            'due_at' => now()->addDay(),
            'status' => 'published',
            'published_at' => now(),
        ]);
        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $this->linkedStudent->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        DB::table('exams')->insert([
            'title' => 'Upcoming Exam',
            'subject_id' => $subject->id,
            'classroom_id' => $this->classroom->id,
            'teacher_id' => $teacher->id,
            'starts_at' => now()->addDay(),
            'duration_minutes' => 30,
            'total_score' => 20,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->parentUser)->get('/parent/attendance?student='.$this->linkedStudent->id)
            ->assertOk()->assertSeeText('حاضر');
        $this->actingAs($this->parentUser)->get('/parent/assignments?student='.$this->linkedStudent->id)
            ->assertOk()->assertSeeText('Homework One')->assertSeeText('تم التسليم');
        $this->actingAs($this->parentUser)->get('/parent/exams?student='.$this->linkedStudent->id)
            ->assertOk()->assertSeeText('Upcoming Exam');
        $this->actingAs($this->parentUser)->get('/parent/assignments?student='.$this->unlinkedStudent->id)
            ->assertNotFound();
    }

    public function test_parent_sees_automatic_result_only_for_linked_child(): void
    {
        $subject = Subject::create(['name' => 'Automatic Results', 'code' => 'AUTO-1', 'stage' => 'Primary', 'status' => 'active']);
        $teacher = $this->teacherForClassroom($this->classroom, $subject);
        $linkedExam = DB::table('exams')->insertGetId([
            'title' => 'Linked Automatic Exam',
            'subject_id' => $subject->id,
            'classroom_id' => $this->classroom->id,
            'teacher_id' => $teacher->id,
            'starts_at' => now()->subHour(),
            'duration_minutes' => 30,
            'total_score' => 10,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignExam = DB::table('exams')->insertGetId([
            'title' => 'Foreign Automatic Exam',
            'subject_id' => $subject->id,
            'classroom_id' => $this->unlinkedStudent->classroom_id,
            'teacher_id' => $teacher->id,
            'starts_at' => now()->subHour(),
            'duration_minutes' => 30,
            'total_score' => 10,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            [$linkedExam, $this->linkedStudent->id, 9, 90],
            [$foreignExam, $this->unlinkedStudent->id, 2, 20],
        ] as [$examId, $studentId, $score, $percentage]) {
            DB::table('exam_attempts')->insert([
                'exam_id' => $examId,
                'student_id' => $studentId,
                'attempt_number' => 1,
                'started_at' => now()->subHour(),
                'expires_at' => now()->subMinutes(30),
                'submitted_at' => now()->subMinutes(30),
                'graded_at' => now()->subMinutes(30),
                'status' => 'submitted',
                'score' => $score,
                'maximum_score' => 10,
                'percentage' => $percentage,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->parentUser)
            ->get('/parent/results?student='.$this->linkedStudent->id)
            ->assertOk()
            ->assertSeeText('Linked Automatic Exam')
            ->assertSeeText('9 / 10')
            ->assertDontSeeText('Foreign Automatic Exam');
        $this->get('/parent/exams?student='.$this->linkedStudent->id)
            ->assertOk()
            ->assertSeeText('Linked Automatic Exam')
            ->assertSeeText('9 / 10')
            ->assertSeeText('Foreign Automatic Exam')
            ->assertDontSeeText('2 / 10');
        $this->get('/parent/results?student='.$this->unlinkedStudent->id)->assertNotFound();
    }

    public function test_parent_can_message_only_admin_or_teacher_assigned_to_the_child_classroom(): void
    {
        $subject = Subject::create(['name' => 'Science', 'code' => 'SCI-1', 'stage' => 'Primary', 'status' => 'active']);
        $teacher = $this->teacherForClassroom($this->classroom, $subject);
        $unassignedTeacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $unassignedTeacherUser->id, 'status' => 'active']);

        $this->actingAs($this->parentUser)->post('/parent/conversations', [
            'student_id' => $this->linkedStudent->id,
            'recipient_id' => $teacher->user_id,
            'subject' => 'Question',
            'body' => 'Please contact me.',
        ])->assertRedirect();

        $conversation = Conversation::firstOrFail();
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversation->id, 'user_id' => $this->parentUser->id]);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $conversation->id, 'user_id' => $teacher->user_id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'sender_id' => $this->parentUser->id, 'body' => 'Please contact me.']);
        $this->actingAs($this->parentUser)->get('/parent/conversations/'.$conversation->id)
            ->assertOk()->assertSeeText('Please contact me.');

        $this->actingAs($this->parentUser)->post('/parent/conversations', [
            'student_id' => $this->linkedStudent->id,
            'recipient_id' => $unassignedTeacherUser->id,
            'body' => 'This must not be sent.',
        ])->assertNotFound();
    }

    public function test_parent_can_open_notifications_and_save_a_subscription_for_own_device(): void
    {
        $this->parentUser->notify(new ParentPortalNotification([
            'title' => 'New grade',
            'body' => 'A result was published.',
            'category' => 'grade',
        ]));

        $this->actingAs($this->parentUser)->get('/parent/notifications')
            ->assertOk()->assertSeeText('New grade');
        $this->actingAs($this->parentUser)->postJson('/parent/push-subscriptions', [
            'endpoint' => 'https://push.example.test/parent-device',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->parentUser->id,
            'endpoint' => 'https://push.example.test/parent-device',
        ]);
    }

    public function test_parent_cannot_open_another_users_conversation_or_subscription(): void
    {
        $otherParent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $conversation = Conversation::create(['created_by' => $otherParent->id, 'status' => 'open']);
        $conversation->participants()->attach($otherParent->id);
        $subscription = $otherParent->pushSubscriptions()->create([
            'endpoint' => 'https://push.example.test/subscription',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ]);

        $this->actingAs($this->parentUser)->get('/parent/conversations/'.$conversation->id)->assertNotFound();
        $this->actingAs($this->parentUser)->delete('/parent/push-subscriptions/'.$subscription->id)->assertNotFound();
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

    private function teacherForClassroom(Classroom $classroom, Subject $subject): Teacher
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);

        return $teacher;
    }
}
