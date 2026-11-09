<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamAttempt;
use App\Models\Guardian;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ExamAttemptService;
use App\Services\TeacherCreationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class QaLearningAcceptanceTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $ctx;
    private Teacher $teacher;
    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        $this->ctx = $this->createStudentFixture('QA-LEARNING');
        $this->ctx['subject'] = $this->createSubjectFor($this->ctx['classroom'], 'QA-LEARNING');
        $this->teacher = Teacher::create(['user_id' => User::factory()->create([
            'name' => 'QA Learning Teacher', 'role' => 'teacher', 'status' => 'active',
        ])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id,
            'classroom_id' => $this->ctx['classroom']->id, 'subject_id' => $this->ctx['subject']->id]);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $guardian->students()->attach($this->ctx['student']);
    }

    public function test_teacher_notification_keeps_context_and_legacy_notifications_render(): void
    {
        foreach (['students' => $this->ctx['user'], 'parents' => $this->parent] as $audience => $recipient) {
            $this->actingAs($this->teacher->user)->post(route('teacher.notifications.store'), [
                'submission_key' => (string) Str::uuid(), 'title' => 'QA context notice', 'body' => 'QA notice content',
                'classroom_id' => $this->ctx['classroom']->id, 'audience' => $audience,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $notice = $recipient->notifications()->firstOrFail();
            $this->assertSame($this->teacher->user->name, $notice->data['teacher_name'] ?? null);
            $this->assertStringContainsString($this->ctx['classroom']->name, $notice->data['classroom_name'] ?? '');
            $recipient->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'LegacyNotice',
                'data' => ['title' => 'QA legacy notice', 'body' => 'QA legacy content']]);
            $this->actingAs($recipient)->get(route($recipient->role.'.notifications'))->assertOk()
                ->assertSeeText($this->teacher->user->name)->assertSeeText($this->ctx['classroom']->name)
                ->assertSeeText('QA legacy content');
        }
    }

    public function test_chat_html_and_poll_use_tripoli_day_and_time_without_changing_utc_storage(): void
    {
        config(['app.display_timezone' => 'Africa/Tripoli']);
        $this->travelTo(Carbon::parse('2026-11-08 23:30:00', 'UTC'));
        $conversation = Conversation::create(['student_id' => $this->ctx['student']->id,
            'created_by' => $this->parent->id, 'status' => 'open', 'last_message_at' => now()]);
        $conversation->participants()->attach([$this->parent->id, $this->teacher->user_id]);
        $this->actingAs($this->parent)->postJson(route('parent.conversations.messages.store', $conversation), ['body' => 'QA midnight message'])
            ->assertCreated()->assertJsonPath('message.time', '01:30')->assertJsonPath('message.day', '2026-11-09')
            ->assertJsonPath('message.created_at', '2026-11-08T23:30:00+00:00');
        $this->assertDatabaseHas('messages', ['body' => 'QA midnight message', 'created_at' => '2026-11-08 23:30:00']);
        $this->actingAs($this->teacher->user)->get(route('teacher.conversations.show', $conversation))->assertOk()->assertSee('>01:30</time>', false);
        $this->getJson(route('teacher.conversations.messages', $conversation))->assertOk()
            ->assertJsonPath('messages.0.time', '01:30')->assertJsonPath('messages.0.day', '2026-11-09');
        $this->get(route('teacher.conversations.index'))->assertOk()->assertSee('>01:30</time>', false);
    }

    public function test_exam_start_respects_exact_window_in_http_and_service(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 08:00:00', 'UTC'));
        $exam = Exam::create(['title' => 'QA exact window', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'teacher_id' => $this->teacher->id,
            'starts_at' => now()->addMinutes(5), 'duration_minutes' => 30, 'total_score' => 10, 'status' => 'scheduled']);
        ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => '2 + 2?', 'type' => 'multiple_choice',
            'options' => ['a' => '4', 'b' => '3'], 'correct_answer' => 'a', 'score' => 10, 'position' => 0]);
        $this->actingAs($this->ctx['user'])->get(route('student.exams.index'))->assertOk()
            ->assertViewHas('examGroups', fn ($groups) => $groups['upcoming']->contains('id', $exam->id));
        $this->post(route('student.exams.start', $exam))->assertUnprocessable();
        try {
            app(ExamAttemptService::class)->start($exam, $this->ctx['student']);
            $this->fail('Service must reject a same-day exam before its start instant.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('exam_attempts', 0);
        }
        $this->travelTo($exam->starts_at);
        $this->post(route('student.exams.start', $exam))->assertRedirect();
        $this->post(route('student.exams.start', $exam))->assertRedirect();
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->travelTo($exam->starts_at->copy()->addMinutes(30));
        $this->post(route('student.exams.start', $exam))->assertUnprocessable();
        $this->assertDatabaseCount('exam_attempts', 1);
    }

    public function test_assignment_file_submission_draft_grade_and_explicit_publication_reach_only_linked_users(): void
    {
        $this->actingAs($this->teacher->user)->post(route('teacher.assignments.store'), [
            'title' => 'QA published assignment result', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'due_date' => now()->addDays(2)->toDateString(),
            'max_score' => 20, 'creation_token' => app(TeacherCreationIntent::class)->issue($this->teacher->id, 'assignments'),
            'attachments' => [UploadedFile::fake()->createWithContent('worksheet.pdf', "%PDF-1.4\nQA worksheet")],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $assignment = Assignment::firstOrFail();
        $attachment = $assignment->attachments()->firstOrFail();
        $this->actingAs($this->ctx['user'])->get(route('student.assignments.show', $assignment))->assertOk()->assertSeeText('worksheet.pdf');
        $this->assertSame("%PDF-1.4\nQA worksheet", $this->get(route('student.assignments.attachments.file', $attachment))->assertOk()->streamedContent());
        $this->post(route('student.assignments.submit', $assignment), [
            'file' => UploadedFile::fake()->createWithContent('answer.pdf', "%PDF-1.4\nQA answer"),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $submission = AssignmentSubmission::firstOrFail();
        $this->actingAs($this->teacher->user)->get(route('teacher.assignments.submissions', $assignment))->assertOk()->assertSeeText($this->ctx['user']->name);
        $this->assertSame("%PDF-1.4\nQA answer", $this->get(route('teacher.submissions.file', $submission))->assertOk()->streamedContent());
        $grade = ['submitted' => [$this->ctx['student']->id => '1'], 'scores' => [$this->ctx['student']->id => 17]];
        $this->post(route('teacher.assignments.submissions.store', $assignment), $grade)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('17.00', $submission->fresh()->score);
        $this->actingAs($this->ctx['user'])->get(route('student.assignments.show', $assignment))->assertOk()->assertDontSeeText('نتيجة الواجب المنشورة');
        $this->actingAs($this->parent)->get(route('parent.assignments'))->assertOk()->assertDontSeeText('نتيجة الواجب المنشورة');
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->teacher->user)->post(route('teacher.assignments.submissions.store', $assignment), $grade + ['publish_results' => '1'])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('graded', $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->graded_at);
        $this->actingAs($this->ctx['user'])->get(route('student.assignments.show', $assignment))->assertOk()->assertSeeText('نتيجة الواجب المنشورة')->assertSeeText('17 / 20');
        $this->actingAs($this->parent)->get(route('parent.assignments'))->assertOk()->assertSeeText('نتيجة الواجب المنشورة')->assertSeeText('17 / 20');
        foreach ([$this->ctx['user'], $this->parent] as $recipient) {
            $notices = $recipient->notifications()->get()->filter(fn ($notice) => ($notice->data['category'] ?? null) === 'assignment_result');
            $this->assertCount(1, $notices);
        }
        $foreign = $this->createStudentFixture('QA-OUTSIDER');
        $this->actingAs($foreign['user'])->get(route('student.assignments.show', $assignment))->assertNotFound();
        $this->get(route('student.assignments.attachments.file', $attachment))->assertNotFound();
        $this->get(route('student.assignments.submission-file', $submission))->assertNotFound();
        $outsider = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $outsider->id, 'status' => 'active']);
        $this->actingAs($outsider)->get(route('parent.assignments', ['student' => $this->ctx['student']->id]))->assertNotFound();
        $this->assertSame(0, $outsider->notifications()->count());

        $otherTeacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->actingAs($otherTeacher->user)->post(route('teacher.assignments.submissions.store', $assignment), $grade + ['publish_results' => '1'])->assertNotFound();
        $this->actingAs($this->teacher->user)->postJson(route('teacher.assignments.submissions.store', $assignment), [
            'scores' => [$this->ctx['student']->id => 21], 'publish_results' => '1',
        ])->assertUnprocessable();
        $this->assertSame('17.00', $submission->fresh()->score);
        $this->post(route('teacher.assignments.submissions.store', $assignment), [
            'submitted' => [$this->ctx['student']->id => '1'], 'scores' => [$this->ctx['student']->id => 0],
        ])->assertRedirect();
        $this->assertSame('0.00', $submission->fresh()->score);
        $this->assertNull($submission->fresh()->graded_at);
        $this->actingAs($this->ctx['user'])->get(route('student.assignments.show', $assignment))->assertDontSee('<h2>نتيجة الواجب المنشورة</h2>', false);
        $this->actingAs($this->parent)->get(route('parent.assignments'))->assertDontSee('نتيجة الواجب المنشورة: <strong>', false);
    }

    public function test_teacher_created_exam_is_answered_and_result_is_approved_before_student_and_parent_receive_it(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 08:00:00', 'UTC'));
        $payload = ['title' => 'QA exam lifecycle', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'starts_at' => now()->addMinutes(5)->format('Y-m-d\TH:i'),
            'duration_minutes' => 30, 'status' => 'scheduled',
            'creation_token' => app(TeacherCreationIntent::class)->issue($this->teacher->id, 'exams'),
            'questions' => [['type' => 'mcq', 'text' => '2 + 2?', 'score' => 10,
                'choices' => [['text' => '4', 'is_correct' => '1'], ['text' => '3', 'is_correct' => '0']]]]];
        $this->actingAs($this->teacher->user)->post(route('teacher.exams.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('teacher.exams.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('exams', 1);
        $exam = Exam::firstOrFail();
        $this->get(route('teacher.exams.index'))->assertOk()->assertSeeText('QA exam lifecycle');
        $this->actingAs($this->ctx['user'])->post(route('student.exams.start', $exam))->assertUnprocessable();
        $this->travelTo($exam->starts_at);
        $this->post(route('student.exams.start', $exam))->assertRedirect();
        $attempt = $exam->attempts()->firstOrFail();
        $question = $exam->questions()->firstOrFail();
        $this->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => $question->correct_answer])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('student.exams.submit', $attempt))->assertRedirect();
        $this->assertSame('100.00', $attempt->fresh()->percentage);
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.results.store'), ['name' => 'QA reviewed results',
            'academic_year_id' => $this->ctx['classroom']->academic_year_id, 'term' => 'QA term',
            'exam_type' => 'QA exam', 'classroom_id' => $this->ctx['classroom']->id])->assertRedirect()->assertSessionHasNoErrors();
        $period = DB::table('exam_periods')->value('id');
        $this->post(route('admin.results.import', $period), ['exam_ids' => [$exam->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->ctx['user'])->get(route('student.results'))->assertDontSeeText('QA reviewed results');
        $this->actingAs($this->parent)->get(route('parent.results'))->assertDontSeeText('QA reviewed results');
        foreach (['under_review', 'approved', 'published'] as $state) {
            $this->actingAs($admin)->patch(route('admin.results.status', $period), ['status' => $state])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($this->ctx['user'])->get(route('student.results'))->assertOk()->assertSeeText('QA reviewed results')->assertSeeText('100%');
        $this->actingAs($this->parent)->get(route('parent.results'))->assertOk()->assertSeeText('QA reviewed results')->assertSeeText('100%');
        foreach ([$this->ctx['user'], $this->parent] as $recipient) {
            $this->assertCount(1, $recipient->notifications()->get()->filter(fn ($notice) => ($notice->data['category'] ?? null) === 'grade'));
        }
    }

    public function test_published_lesson_keeps_safe_formatting_and_stored_attachment_for_authorized_student(): void
    {
        $this->actingAs($this->teacher->user)->get(route('teacher.lessons.create'))->assertOk()->assertSeeText($this->ctx['subject']->code);
        $this->post(route('teacher.lessons.store'), ['title' => 'QA formatted lesson',
            'classroom_id' => $this->ctx['classroom']->id, 'subject_id' => $this->ctx['subject']->id,
            'submission_key' => (string) Str::uuid(), 'status' => 'published', 'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'content' => '<p>QA paragraph</p><ul><li><strong>QA key fact</strong></li></ul><script>alert(1)</script><p onclick="alert(2)">QA safe text</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('lesson.pdf', "%PDF-1.4\nQA lesson attachment")],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::firstOrFail();
        $attachment = $lesson->attachments()->firstOrFail();
        $this->actingAs($this->ctx['user'])->get(route('student.lessons.show', [$this->ctx['subject'], $lesson]))->assertOk()
            ->assertSee('<p>QA paragraph</p>', false)->assertSee('<ul><li><strong>QA key fact</strong></li></ul>', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('onclick="alert(2)"', false);
        $this->assertSame("%PDF-1.4\nQA lesson attachment", $this->get(route('student.lessons.attachments.download', [$this->ctx['subject'], $lesson, $attachment]))->assertOk()->streamedContent());
        $outsider = $this->createStudentFixture('QA-LESSON-OTHER');
        $this->actingAs($outsider['user'])->get(route('student.lessons.show', [$this->ctx['subject'], $lesson]))->assertNotFound();
        $this->get(route('student.lessons.attachments.download', [$this->ctx['subject'], $lesson, $attachment]))->assertNotFound();
    }

    public function test_teacher_can_review_manual_answers_with_bounds_and_cannot_review_another_teachers_exam(): void
    {
        $exam = Exam::create(['title' => 'QA manual review', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'teacher_id' => $this->teacher->id,
            'starts_at' => now()->subMinute(), 'duration_minutes' => 30, 'total_score' => 20, 'status' => 'published']);
        $objective = ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => '2 + 2?', 'type' => 'multiple_choice',
            'options' => ['a' => '4', 'b' => '3'], 'correct_answer' => 'a', 'score' => 10, 'position' => 0]);
        $manual = ExamQuestion::create(['exam_id' => $exam->id, 'question_text' => 'Explain the answer', 'type' => 'short_answer', 'score' => 10, 'position' => 1]);
        $this->actingAs($this->ctx['user'])->post(route('student.exams.start', $exam))->assertRedirect();
        $attempt = ExamAttempt::firstOrFail();
        $this->post(route('student.exams.answers.save', [$attempt, $objective]), ['answer' => 'a'])->assertRedirect();
        $this->post(route('student.exams.answers.save', [$attempt, $manual]), ['answer' => 'QA explanation'])->assertRedirect();
        $this->post(route('student.exams.submit', $attempt))->assertRedirect();
        $this->assertSame('pending_review', $attempt->fresh()->status);
        $manualAnswer = $attempt->answers()->where('exam_question_id', $manual->id)->firstOrFail();
        $objectiveAnswer = $attempt->answers()->where('exam_question_id', $objective->id)->firstOrFail();
        $base = '/teacher/exams/'.$exam->id.'/attempts';
        $this->actingAs($this->teacher->user)->get($base)->assertOk()->assertSeeText('QA explanation');
        $this->postJson($base.'/'.$attempt->id, ['scores' => [$manualAnswer->id => 11]])->assertUnprocessable();
        $this->assertNull($manualAnswer->fresh()->awarded_score);
        $this->postJson($base.'/'.$attempt->id, ['scores' => [$manualAnswer->id => 7, $objectiveAnswer->id => 0]])->assertUnprocessable();
        $this->post($base.'/'.$attempt->id, ['scores' => [$manualAnswer->id => 7]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('17.00', $attempt->fresh()->score);
        $this->assertSame('85.00', $attempt->fresh()->percentage);
        $this->assertSame('10.00', $objectiveAnswer->fresh()->awarded_score);
        $this->actingAs($this->parent)->get(route('parent.results'))->assertDontSeeText('QA manual review');
        $foreign = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->actingAs($foreign->user)->get($base)->assertNotFound();
        $this->post($base.'/'.$attempt->id, ['scores' => [$manualAnswer->id => 1]])->assertNotFound();
        $this->assertSame('17.00', $attempt->fresh()->score);
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.results.store'), ['name' => 'QA manual approved results',
            'academic_year_id' => $this->ctx['classroom']->academic_year_id, 'term' => 'QA term',
            'exam_type' => 'QA exam', 'classroom_id' => $this->ctx['classroom']->id])->assertRedirect()->assertSessionHasNoErrors();
        $period = DB::table('exam_periods')->value('id');
        $this->post(route('admin.results.import', $period), ['exam_ids' => [$exam->id]])->assertRedirect()->assertSessionHasNoErrors();
        foreach (['under_review', 'approved', 'published'] as $state) {
            $this->patch(route('admin.results.status', $period), ['status' => $state])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->actingAs($this->ctx['user'])->get(route('student.results'))->assertOk()->assertSeeText('QA manual approved results')->assertSeeText('85%');
        $this->actingAs($this->parent)->get(route('parent.results'))->assertOk()->assertSeeText('QA manual approved results')->assertSeeText('85%');
        $this->actingAs($this->teacher->user)->post($base.'/'.$attempt->id, ['scores' => [$manualAnswer->id => 6]])->assertUnprocessable();
    }
}
