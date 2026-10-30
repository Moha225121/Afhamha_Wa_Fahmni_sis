<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherCreationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TeacherHighSeverityFixTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ctx = $this->context('own');
    }

    public function test_assignment_creation_replay_creates_one_record_and_attachment(): void
    {
        $data = $this->assignmentData();
        $data['creation_token'] = $this->intent('assignments');
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.assignments.store'), $data + [
                'attachments' => [UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf')],
            ])->assertRedirect(route('teacher.assignments.index'));
        }
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseCount('assignment_attachments', 1);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_new_assignment_intent_allows_identical_content(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.assignments.store'), $this->assignmentData() + [
                'creation_token' => $this->intent('assignments'),
            ])->assertRedirect();
        }
        $this->assertDatabaseCount('assignments', 2);
    }

    public function test_teacher_created_assignment_accepts_eligible_student_submission(): void
    {
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.assignments.store'), $this->assignmentData() + [
            'creation_token' => $this->intent('assignments'),
        ])->assertRedirect();
        $assignment = Assignment::firstOrFail();
        $this->actingAs($this->ctx['student']->user)->get(route('student.assignments.index'))->assertSeeText($assignment->title);
        $this->post(route('student.assignments.submit', $assignment), [
            'file' => UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $this->assertDatabaseHas('assignment_submissions', ['assignment_id' => $assignment->id, 'student_id' => $this->ctx['student']->id]);
        $this->assertSame('published', $assignment->status);
        $this->actingAs($this->ctx['teacher']->user)->get(route('teacher.assignments.index', ['status' => 'completed']))->assertSeeText($assignment->title);
    }

    public static function assignmentStates(): array
    {
        return [
            'legacy active' => ['active', 'future', false, 302],
            'published' => ['published', 'future', false, 302],
            'cancelled' => ['cancelled', 'future', false, 404],
            'draft' => ['draft', 'future', false, 404],
            'expired active' => ['active', 'past', false, 422],
            'expired published' => ['published', 'past', false, 422],
            'future publication' => ['published', 'future', true, 404],
        ];
    }

    #[DataProvider('assignmentStates')]
    public function test_assignment_lifecycle_submission_rules(string $status, string $deadline, bool $futurePublication, int $expected): void
    {
        $assignment = Assignment::create([
            'title' => 'Lifecycle assignment', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'teacher_id' => $this->ctx['teacher']->id,
            'status' => $status, 'published_at' => $futurePublication ? now()->addDay() : now()->subMinute(),
            'due_at' => $deadline === 'past' ? now()->subMinute() : now()->addDay(),
        ]);
        $this->actingAs($this->ctx['student']->user)->postJson(route('student.assignments.submit', $assignment), [
            'file' => UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf'),
        ])->assertStatus($expected);
        $this->assertDatabaseCount('assignment_submissions', $expected === 302 ? 1 : 0);
    }

    public function test_another_teacher_with_the_same_curriculum_pair_cannot_view_or_grade_the_owners_submissions(): void
    {
        $assignment = Assignment::create([
            'teacher_id' => $this->ctx['teacher']->id, 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'title' => 'QA Ownership', 'status' => 'published',
            'due_at' => now()->addDay(), 'max_score' => 20,
        ]);
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $otherTeacher->classrooms()->attach($this->ctx['classroom'], ['subject_id' => $this->ctx['subject']->id]);
        $this->actingAs($otherUser)->get(route('teacher.assignments.submissions', $assignment))->assertNotFound();
        $this->post(route('teacher.assignments.submissions.store', $assignment), [
            'submitted' => [$this->ctx['student']->id => 1], 'scores' => [$this->ctx['student']->id => 19],
        ])->assertNotFound();
        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_unrelated_student_cannot_submit_teacher_created_assignment(): void
    {
        $other = $this->context('other');
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.assignments.store'), $this->assignmentData() + [
            'creation_token' => $this->intent('assignments'),
        ])->assertRedirect();
        $assignment = Assignment::firstOrFail();
        $this->actingAs($other['student']->user)->postJson(route('student.assignments.submit', $assignment), [
            'file' => UploadedFile::fake()->create('foreign.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_assignment_filters_preserve_active_published_closed_and_cancelled_rows(): void
    {
        foreach (['active', 'published', 'cancelled'] as $status) {
            DB::table('assignments')->insert($this->assignmentAttributes('Filter '.$status, $status, now()->addDays(2)));
        }
        DB::table('assignments')->insert($this->assignmentAttributes('Filter expired', 'published', now()->subDay()));
        $this->actingAs($this->ctx['teacher']->user)->get(route('teacher.assignments.index', ['status' => 'active']))
            ->assertOk()->assertSeeText('Filter active')->assertSeeText('Filter published')
            ->assertDontSeeText('Filter expired')->assertDontSeeText('Filter cancelled');
        $this->get(route('teacher.assignments.index', ['status' => 'closed']))
            ->assertSeeText('Filter expired')->assertDontSeeText('Filter published');
        $this->get(route('teacher.assignments.index', ['status' => 'cancelled']))
            ->assertSeeText('Filter cancelled')->assertDontSeeText('Filter active');
        DB::table('assignment_submissions')->insert([
            'assignment_id' => Assignment::where('title', 'Filter published')->value('id'),
            'student_id' => $this->ctx['student']->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get(route('teacher.assignments.index', ['status' => 'completed']))->assertSeeText('Filter published');
    }

    public function test_exam_creation_replay_creates_one_exam_question_and_choices(): void
    {
        $data = $this->examData() + ['creation_token' => $this->intent('exams')];
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $data)->assertRedirect();
        }
        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseCount('exam_questions', 1);
        $this->assertDatabaseCount('exam_choices', 2);
    }

    public function test_new_exam_intent_allows_identical_content_and_edit_needs_no_token(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $this->examData() + [
                'creation_token' => $this->intent('exams'),
            ])->assertRedirect();
        }
        $this->assertDatabaseCount('exams', 2);
        $this->assertDatabaseCount('exam_questions', 2);
        $exam = Exam::firstOrFail();
        $this->put(route('teacher.exams.update', $exam), array_replace($this->examData(), ['title' => 'Edited exam']))->assertRedirect();
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'title' => 'Edited exam']);
        $this->assertDatabaseCount('exam_questions', 2);
        $this->assertDatabaseCount('exam_choices', 4);
    }

    public function test_incomplete_draft_cannot_publish_through_status_endpoint(): void
    {
        $data = $this->examData();
        $data['questions'][0]['choices'][0]['is_correct'] = false;
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $data + [
            'creation_token' => $this->intent('exams'),
        ])->assertRedirect();
        $exam = Exam::firstOrFail();
        $before = DB::table('exams')->where('id', $exam->id)->first();
        $this->patchJson(route('teacher.exams.status', $exam), ['status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('questions.0.choices');
        $this->assertEquals($before, DB::table('exams')->where('id', $exam->id)->first());
        $this->assertDatabaseCount('exam_choices', 2);
    }

    public static function invalidPublications(): array
    {
        return [
            'zero correct scheduled' => [0, 2, 'mcq', 'draft', 'scheduled'],
            'two correct published' => [2, 2, 'mcq', 'draft', 'published'],
            'one choice' => [1, 1, 'mcq', 'draft', 'published'],
            'true false zero correct' => [0, 2, 'true_false', 'draft', 'published'],
            'completed source cannot bypass' => [0, 2, 'mcq', 'completed', 'published'],
        ];
    }

    #[DataProvider('invalidPublications')]
    public function test_all_objective_status_publications_validate_choices(int $correct, int $count, string $type, string $source, string $target): void
    {
        $data = $this->examData();
        $data['questions'][0]['type'] = $type;
        $data['questions'][0]['choices'] = array_slice($data['questions'][0]['choices'], 0, $count);
        foreach ($data['questions'][0]['choices'] as $i => &$choice) {
            $choice['is_correct'] = $i < $correct;
        }
        unset($choice);
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $data + [
            'creation_token' => $this->intent('exams'),
        ])->assertRedirect();
        $exam = Exam::firstOrFail();
        $exam->update(['status' => $source]);
        $this->patchJson(route('teacher.exams.status', $exam), ['status' => $target])->assertUnprocessable();
        $this->assertSame($source, $exam->fresh()->status);
    }

    public function test_empty_draft_cannot_publish(): void
    {
        $data = array_replace($this->examData(), ['questions' => []]);
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $data + [
            'creation_token' => $this->intent('exams'),
        ])->assertRedirect();
        $exam = Exam::firstOrFail();
        $this->patchJson(route('teacher.exams.status', $exam), ['status' => 'published'])->assertUnprocessable();
        $this->assertSame('draft', $exam->fresh()->status);
    }

    public function test_valid_exam_can_publish_and_republish_without_duplicate_choices(): void
    {
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.exams.store'), $this->examData() + [
            'creation_token' => $this->intent('exams'),
        ])->assertRedirect();
        $exam = Exam::firstOrFail();
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson(route('teacher.exams.status', $exam), ['status' => 'published'])->assertRedirect();
        }
        $this->assertSame('published', $exam->fresh()->status);
        $this->assertDatabaseCount('exam_questions', 1);
        $this->assertDatabaseCount('exam_choices', 2);
    }

    public function test_legacy_valid_published_exam_remains_publishable(): void
    {
        $exam = Exam::create($this->examAttributes() + ['status' => 'published']);
        ExamQuestion::create([
            'exam_id' => $exam->id, 'question_text' => 'Legacy question', 'type' => 'multiple_choice',
            'options' => ['a' => 'A', 'b' => 'B'], 'correct_answer' => 'a', 'score' => 10, 'position' => 0,
        ]);
        $this->actingAs($this->ctx['teacher']->user)->patchJson(route('teacher.exams.status', $exam), ['status' => 'published'])->assertRedirect();
        $this->assertSame('published', $exam->fresh()->status);
    }

    public function test_foreign_teacher_cannot_change_exam_status(): void
    {
        $other = $this->context('foreign');
        $exam = Exam::create($this->examAttributes() + ['status' => 'draft']);
        $this->actingAs($other['teacher']->user)->patchJson(route('teacher.exams.status', $exam), ['status' => 'published'])->assertNotFound();
        $this->assertSame('draft', $exam->fresh()->status);
    }

    public function test_missing_unknown_or_foreign_creation_intents_cannot_create(): void
    {
        foreach (['assignments', 'exams'] as $kind) {
            $data = $kind === 'assignments' ? $this->assignmentData() : $this->examData();
            $this->actingAs($this->ctx['teacher']->user)->postJson(route("teacher.$kind.store"), $data)->assertUnprocessable();
            $this->postJson(route("teacher.$kind.store"), $data + ['creation_token' => (string) Str::uuid()])->assertUnprocessable();
            $token = $this->intent($kind);
            $other = $this->context('foreign-'.$kind);
            $data['classroom_id'] = $other['classroom']->id;
            $data['subject_id'] = $other['subject']->id;
            $this->actingAs($other['teacher']->user)->postJson(route("teacher.$kind.store"), $data + ['creation_token' => $token])->assertUnprocessable();
            $this->assertDatabaseCount($kind, 0);
        }
    }

    public function test_invalid_publication_does_not_consume_intent_and_form_paths_share_rules(): void
    {
        $token = $this->intent('exams');
        $data = $this->examData();
        $data['questions'][0]['choices'][0]['is_correct'] = false;
        $this->actingAs($this->ctx['teacher']->user)->postJson(route('teacher.exams.store'), array_replace($data, ['status' => 'scheduled', 'creation_token' => $token]))->assertUnprocessable();
        $this->assertDatabaseCount('exams', 0);
        $this->post(route('teacher.exams.store'), $data + ['creation_token' => $token])->assertRedirect();
        $exam = Exam::firstOrFail();
        $this->putJson(route('teacher.exams.update', $exam), array_replace($data, ['status' => 'published']))->assertUnprocessable();
        $this->assertSame('draft', $exam->fresh()->status);
        $this->put(route('teacher.exams.update', $exam), array_replace($this->examData(), ['status' => 'published']))->assertRedirect();
        $this->assertSame('published', $exam->fresh()->status);
    }

    private function intent(string $kind): string
    {
        $response = $this->actingAs($this->ctx['teacher']->user)->get(route("teacher.$kind.create"))->assertOk();
        preg_match('/name="creation_token" value="([^"]+)"/', $response->getContent(), $match);

        $this->assertArrayHasKey(1, $match, 'A create form must issue its own creation intent.');

        return $match[1];
    }

    public function test_failed_creation_rolls_back_resource_and_keeps_intent_reusable(): void
    {
        $token = $this->intent('assignments');
        $intents = app(TeacherCreationIntent::class);
        try {
            $intents->once($token, $this->ctx['teacher']->id, 'assignments', function (): int {
                DB::table('assignments')->insertGetId($this->assignmentAttributes('Rollback', 'published', now()->addDay()));
                throw new RuntimeException('QA simulated creation failure');
            });
            $this->fail('The simulated failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('QA simulated creation failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseHas('teacher_creation_intents', ['token' => $token, 'resource_id' => null]);
        $id = $intents->once($token, $this->ctx['teacher']->id, 'assignments',
            fn () => DB::table('assignments')->insertGetId($this->assignmentAttributes('Retry', 'published', now()->addDay())));
        $this->assertSame($id, $intents->once($token, $this->ctx['teacher']->id, 'assignments',
            fn () => throw new RuntimeException('A completed intent must never execute again')));
        $this->assertDatabaseCount('assignments', 1);
    }

    public function test_creation_intent_is_bound_to_resource_kind(): void
    {
        $token = $this->intent('exams');
        $this->actingAs($this->ctx['teacher']->user)->postJson(route('teacher.assignments.store'),
            $this->assignmentData() + ['creation_token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseCount('exams', 0);
    }

    public function test_assignment_edit_does_not_require_a_creation_token(): void
    {
        $this->actingAs($this->ctx['teacher']->user)->post(route('teacher.assignments.store'),
            $this->assignmentData() + ['creation_token' => $this->intent('assignments')])->assertRedirect();
        $assignment = Assignment::firstOrFail();
        $this->put(route('teacher.assignments.update', $assignment),
            array_replace($this->assignmentData(), ['title' => 'Edited assignment']))->assertRedirect();
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('assignments', ['id' => $assignment->id, 'title' => 'Edited assignment', 'status' => 'published']);
    }

    public function test_invalid_token_validation_can_render_the_form_again(): void
    {
        $this->actingAs($this->ctx['teacher']->user)->from(route('teacher.assignments.create'))
            ->post(route('teacher.assignments.store'), $this->assignmentData() + ['creation_token' => ['invalid']])
            ->assertRedirect(route('teacher.assignments.create'))->assertSessionHasErrors('creation_token');
        $this->get(route('teacher.assignments.create'))->assertOk()->assertSee('name="creation_token"', false);
        $this->assertDatabaseCount('assignments', 0);
    }

    private function assignmentData(): array
    {
        return ['title' => 'QA replay assignment', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'due_date' => now()->addDays(2)->toDateString(), 'max_score' => 20];
    }

    private function assignmentAttributes(string $title, string $status, $due): array
    {
        return ['title' => $title, 'classroom_id' => $this->ctx['classroom']->id, 'subject_id' => $this->ctx['subject']->id,
            'teacher_id' => $this->ctx['teacher']->id, 'status' => $status, 'published_at' => now(),
            'due_at' => $due, 'due_date' => $due->toDateString(), 'max_score' => 20, 'created_at' => now(), 'updated_at' => now()];
    }

    private function examData(): array
    {
        return ['title' => 'QA replay exam', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'duration_minutes' => 30, 'status' => 'draft', 'questions' => [[
                'type' => 'mcq', 'text' => '2 + 2?', 'score' => 10, 'choices' => [
                    ['text' => '4', 'is_correct' => true], ['text' => '3', 'is_correct' => false],
                ],
            ]]];
    }

    private function examAttributes(): array
    {
        return ['title' => 'QA status exam', 'classroom_id' => $this->ctx['classroom']->id,
            'subject_id' => $this->ctx['subject']->id, 'teacher_id' => $this->ctx['teacher']->id,
            'starts_at' => now()->addDay(), 'duration_minutes' => 30, 'total_score' => 10];
    }

    private function context(string $suffix): array
    {
        $year = AcademicYear::create(['name' => "QA-$suffix", 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $classroom = Classroom::create(['name' => "QA Class $suffix", 'stage' => 'Primary', 'section' => $suffix, 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => "QA Subject $suffix", 'code' => "QA-$suffix", 'stage' => 'Primary', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $student = Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id,
            'student_number' => "QA-$suffix", 'classroom_id' => $classroom->id, 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);

        return compact('teacher', 'student', 'classroom', 'subject');
    }
}
