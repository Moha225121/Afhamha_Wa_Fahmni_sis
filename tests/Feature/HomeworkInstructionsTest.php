<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HomeworkInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private User $parent;

    private Assignment $homework;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo('2026-10-25 10:00:00');
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $room = Classroom::create(['name' => 'الصف الأول', 'stage' => 'أساسي', 'section' => 'C', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'اللغة الإنجليزية', 'code' => 'HW-EN', 'stage' => 'أساسي', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->student = Student::create(['user_id' => User::factory()->create(['name' => 'طالب تجريبي', 'role' => 'student', 'status' => 'active'])->id, 'student_number' => 'HOMEWORK-RENDER-1', 'classroom_id' => $room->id, 'status' => 'active']);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($this->student->id);
        $this->homework = Assignment::create(['title' => 'Reading - Grammar', 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'instructions' => '<div><strong>القراءة</strong><br>راجع الصفحة 23.</div><p>حل التمرين في البيت.</p>', 'due_date' => '2026-10-25', 'due_at' => '2026-10-25 00:00:00', 'status' => 'active', 'published_at' => now()->subHour()]);
    }

    private function studentPage()
    {
        return $this->actingAs($this->student->user)->get(route('student.assignments.show', $this->homework))->assertOk();
    }

    public function test_student_and_linked_parent_receive_safe_formatted_instructions_instead_of_visible_html_tags(): void
    {
        $this->studentPage()->assertSee('<strong>القراءة</strong>', false)->assertSeeText('راجع الصفحة 23.')->assertDontSee('&lt;div&gt;', false);
        $this->actingAs($this->parent)->get('/parent/assignments?student='.$this->student->id)
            ->assertOk()->assertSee('<strong>القراءة</strong>', false)->assertDontSee('&lt;div&gt;', false);
    }

    public function test_stored_homework_cannot_execute_scripts_event_handlers_or_unsafe_styles_in_either_portal(): void
    {
        $this->homework->update(['instructions' => '<div onclick="homeworkAttack()" style="background-image:url(javascript:homeworkAttack())"><b>نص آمن</b></div><script>homeworkAttack()</script><img src=x onerror="homeworkAttack()"><iframe src="https://attacker.test"></iframe>']);
        foreach ([$this->studentPage(), $this->actingAs($this->parent)->get('/parent/assignments?student='.$this->student->id)->assertOk()] as $response) {
            $response->assertSee('<b>نص آمن</b>', false)->assertDontSee('homeworkAttack', false)->assertDontSee('attacker.test', false);
        }
    }

    public function test_description_fallback_plain_text_and_long_instructions_remain_readable(): void
    {
        $this->homework->update(['instructions' => null, 'description' => "السطر الأول\nالسطر الثاني: 2 < 5 & 8 > 3"]);
        $this->studentPage()->assertSeeText('السطر الأول')->assertSeeText('السطر الثاني: 2 < 5 & 8 > 3');
        $long = str_repeat('<p>تعليمات عربية طويلة</p>', 1200).'<p>النهاية محفوظة بالكامل</p>';
        $this->homework->update(['instructions' => $long]);
        $this->studentPage()->assertSeeText('النهاية محفوظة بالكامل');
    }

    public function test_missing_subject_uses_a_fallback_without_breaking_homework_pages(): void
    {
        // A deleted subject cascades its assignments in the schema. Exercise a
        // missing relation directly without deleting the homework under test.
        $this->actingAs($this->student->user)->get(route('student.assignments.index'))->assertOk();
        $this->homework->setRelation('subject', null);
        $html = view('student.assignments.show', ['student' => $this->student, 'assignment' => $this->homework, 'submission' => null])->render();
        $this->assertStringContainsString('مادة غير محددة', $html);
        $assignments = new LengthAwarePaginator([$this->homework], 1, 20);
        $html = view('student.assignments.index', ['student' => $this->student, 'assignments' => $assignments])->render();
        $this->assertStringContainsString('مادة غير محددة', $html);
    }

    public function test_chosen_due_day_keeps_the_upload_form_open_and_displays_the_same_school_deadline_everywhere(): void
    {
        $this->studentPage()->assertSeeText('2026-10-25 23:59')->assertSee('class="profile-form homework-upload"', false);
        $this->get(route('student.assignments.index'))->assertOk()->assertSeeText('2026-10-25 23:59')->assertSeeText('متاح · لم يسلّم');
        $this->actingAs($this->parent)->get('/parent/assignments?student='.$this->student->id)->assertOk()->assertSeeText('2026-10-25 23:59');
    }

    public function test_expired_and_graded_homework_explain_why_upload_is_unavailable(): void
    {
        $this->homework->update(['due_at' => now()->subMinute(), 'due_date' => null]);
        $this->studentPage()->assertSeeText('انتهى موعد تسليم هذا الواجب.')->assertDontSee('class="profile-form homework-upload"', false);
        $this->homework->update(['due_at' => now()->addDay()]);
        AssignmentSubmission::create(['assignment_id' => $this->homework->id, 'student_id' => $this->student->id, 'submitted_at' => now()->subHour(), 'status' => 'graded', 'score' => 0]);
        $this->studentPage()->assertSeeText('تم تصحيح تسليمك')->assertDontSee('class="profile-form homework-upload"', false);
    }
}
