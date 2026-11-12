<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ExamAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class Round2LearningTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $ctx;
    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ctx = $this->createStudentFixture('R2');
        $this->ctx['subject'] = $this->createSubjectFor($this->ctx['classroom'], 'R2');
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->ctx['classroom']->id, 'subject_id' => $this->ctx['subject']->id]);
    }

    public function test_assignment_note_persists_updates_and_is_visible_safely_beside_the_file_in_both_teacher_views(): void
    {
        $assignment = Assignment::create(['title' => 'R2 assignment', 'classroom_id' => $this->ctx['classroom']->id, 'subject_id' => $this->ctx['subject']->id, 'teacher_id' => $this->teacher->id, 'status' => 'published', 'published_at' => now(), 'due_at' => now()->addDay(), 'due_date' => now()->addDay()->toDateString(), 'max_score' => 10]);
        foreach (["السطر الأول\nالسطر الثاني <script>alert(1)</script>", 'ملاحظة معدلة', ''] as $note) {
            $this->actingAs($this->ctx['user'])->post(route('student.assignments.submit', $assignment), ['file' => UploadedFile::fake()->createWithContent('answer.pdf', "%PDF-1.4\nR2 answer"), 'notes' => $note])->assertRedirect()->assertSessionHasNoErrors();
            $submission = AssignmentSubmission::firstOrFail();
            $this->assertSame($note ?: null, $submission->notes);
            $this->assertDatabaseCount('assignment_submissions', 1);
            foreach (['teacher.assignments.submissions', 'teacher.assignments.show'] as $route) {
                $response = $this->actingAs($this->teacher->user)->get(route($route, $assignment))->assertOk();
                if ($note !== '') $response->assertSee($note)->assertSee('white-space: pre-wrap', false);
                $response->assertDontSee('<script>alert(1)</script>', false)->assertSee(route('teacher.submissions.file', $submission), false);
            }
        }
        $submission->update(['status' => 'graded', 'graded_at' => now(), 'score' => 8]);
        $this->actingAs($this->ctx['user'])->post(route('student.assignments.submit', $assignment), ['file' => UploadedFile::fake()->createWithContent('again.pdf', "%PDF-1.4\nnew answer"), 'notes' => 'لا تعدل المصحح'])->assertSessionHasErrors();
        $this->assertNull($submission->fresh()->notes);
        $outsider = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->actingAs($outsider->user)->get(route('teacher.assignments.submissions', $assignment))->assertNotFound();
    }

    public function test_editor_markup_is_sanitized_consistently_in_student_attempt_teacher_review_and_edit_preview(): void
    {
        $exam = $this->exam('published');
        $content = '<div onclick="bad()"><strong>سؤال عربي</strong><br>2 &lt; 5&nbsp; و 7 &gt; 3<script>alert(1)</script><img src=x onerror="bad()"></div>';
        $question = ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => $content, 'text' => $content, 'type' => 'short_answer', 'score' => 10, 'position' => 0]);
        $attempt = app(ExamAttemptService::class)->start($exam, $this->ctx['student']);
        $this->actingAs($this->ctx['user'])->get(route('student.exams.attempt', $attempt))->assertOk()->assertSee('<strong>سؤال عربي</strong><br>', false)->assertDontSee('&lt;div', false)->assertDontSee('bad()', false)->assertDontSee('alert(1)', false);
        $attempt->update(['status' => 'pending_review', 'submitted_at' => now()]);
        $this->actingAs($this->teacher->user)->get(route('teacher.exams.attempts.index', $exam))->assertOk()->assertSee('<strong>سؤال عربي</strong><br>', false)->assertDontSee('bad()', false)->assertDontSee('alert(1)', false);
        $exam->update(['status' => 'draft']);
        $this->get(route('teacher.exams.edit', $exam))->assertOk()->assertDontSee('bad()', false)->assertDontSee('alert(1)', false);
        $this->assertSame($content, $question->fresh()->question_text, 'Display must not rewrite stored historical content.');
    }

    public function test_legacy_escaped_questions_and_plain_comparisons_preserve_readable_content(): void
    {
        $exam = $this->exam('published');
        foreach (['&lt;div&gt;قديم&lt;br&gt;سطر ثان&lt;/div&gt;', "2 < 5 & 7 > 3\nسؤال جديد"] as $index => $content) {
            ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => $content, 'type' => 'short_answer', 'score' => 5, 'position' => $index]);
        }
        $attempt = app(ExamAttemptService::class)->start($exam, $this->ctx['student']);
        $this->actingAs($this->ctx['user'])->get(route('student.exams.attempt', $attempt))->assertOk()->assertSee('<div>قديم<br>سطر ثان</div>', false)->assertSee('2 &lt; 5 &amp; 7 &gt; 3', false)->assertSee("<br />\nسؤال جديد", false);
    }

    public function test_exam_deletion_preserves_attempt_answers_and_explains_cancellation(): void
    {
        $exam = $this->exam('published');
        $question = ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => 'سؤال', 'type' => 'short_answer', 'score' => 10, 'position' => 0]);
        $attempt = app(ExamAttemptService::class)->start($exam, $this->ctx['student']);
        $this->actingAs($this->teacher->user)->delete(route('teacher.exams.destroy', $exam))->assertRedirect()->assertSessionHasErrors('exam');
        $this->assertDatabaseHas('exam_questions', ['id' => $question->id]);
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attempt->id]);
        $this->patch(route('teacher.exams.cancel', $exam))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'cancelled', 'module' => 'exams', 'record_id' => $exam->id]);
        $this->delete(route('teacher.exams.destroy', $exam))->assertSessionHasErrors('exam');
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->actingAs($this->ctx['user'])->post(route('student.exams.start', $exam))->assertNotFound();
    }

    public function test_unused_draft_can_be_deleted_but_scheduled_exam_requires_cancellation_and_foreign_requests_fail(): void
    {
        $draft = $this->exam('draft');
        $this->actingAs($this->teacher->user)->delete(route('teacher.exams.destroy', $draft))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('exams', ['id' => $draft->id]);
        $audit = DB::table('audit_logs')->where('action', 'deleted')->where('module', 'exams')->where('record_id', $draft->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($draft->title, json_decode($audit->old_values, true)['title']);
        $this->delete(route('teacher.exams.destroy', $draft))->assertNotFound();
        $scheduled = $this->exam('scheduled');
        $scheduled->update(['starts_at' => now()->addDay()]);
        $this->delete(route('teacher.exams.destroy', $scheduled))->assertSessionHasErrors('exam');
        $this->patch(route('teacher.exams.cancel', $scheduled))->assertRedirect()->assertSessionHasNoErrors();
        $this->patch(route('teacher.exams.cancel', $scheduled))->assertRedirect()->assertSessionHasNoErrors();
        $other = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->actingAs($other->user)->delete(route('teacher.exams.destroy', $scheduled))->assertNotFound();
        $this->actingAs($this->teacher->user)->delete(route('teacher.exams.destroy', $scheduled))->assertRedirect()->assertSessionHasNoErrors();
        $graded = $this->exam('draft');
        DB::table('grades')->insert(['exam_id' => $graded->id, 'student_id' => $this->ctx['student']->id, 'score' => 6]);
        $this->delete(route('teacher.exams.destroy', $graded))->assertSessionHasErrors('exam');
        $this->assertDatabaseHas('grades', ['exam_id' => $graded->id, 'score' => 6]);
    }

    public function test_subject_selectors_include_codes_without_changing_values(): void
    {
        foreach (['teacher.lessons.create', 'teacher.exams.create', 'teacher.assignments.create'] as $route) {
            $this->actingAs($this->teacher->user)->get(route($route))->assertOk()->assertSeeText($this->ctx['subject']->code)->assertSee('value="'.$this->ctx['subject']->id.'"', false);
        }
    }

    private function exam(string $status): Exam
    {
        return Exam::create(['title' => 'R2 exam', 'teacher_id' => $this->teacher->id, 'subject_id' => $this->ctx['subject']->id, 'classroom_id' => $this->ctx['classroom']->id, 'starts_at' => now()->subMinute(), 'duration_minutes' => 30, 'total_score' => 10, 'status' => $status]);
    }
}
