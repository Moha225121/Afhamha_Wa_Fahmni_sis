<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AssignmentSubmissionService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudentHomeworkSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public static function acceptedFiles(): array
    {
        return [['pdf'], ['jpeg']];
    }

    #[DataProvider('acceptedFiles')]
    public function test_secondary_selected_branch_can_upload_read_replace_and_download(string $extension): void
    {
        Storage::fake('local');
        $ctx = $this->context();
        $assignment = $ctx['assignment'];
        $student = $ctx['student'];
        $show = route('student.assignments.show', $assignment);

        $this->actingAs($student->user)->get(route('student.assignments.index'))
            ->assertOk()->assertSeeText($assignment->title);
        $this->get($show)->assertOk();
        $this->from($show)->post(route('student.assignments.submit', $assignment), [
            'file' => $this->file('answer.'.$extension), 'notes' => 'إجابة الطالب',
        ])->assertRedirect($show)->assertSessionHasNoErrors();

        $submission = AssignmentSubmission::firstOrFail();
        $firstPath = $submission->fileAttachment->path;
        Storage::disk('local')->assertExists($firstPath);
        $this->assertSame($student->id, $submission->student_id);
        $this->assertSame('submitted', $submission->status);
        $this->assertSame('إجابة الطالب', $submission->notes);
        $this->get($show)->assertOk()->assertSeeText('answer.'.$extension)->assertSeeText('تم التسليم');
        $this->get(route('student.assignments.submission-file', $submission))
            ->assertOk()->assertDownload('answer.'.$extension);

        $this->from($show)->post(route('student.assignments.submit', $assignment), [
            'file' => $this->file('replacement.'.$extension),
        ])->assertRedirect($show)->assertSessionHasNoErrors();
        $submission->refresh();
        $newAttachment = $submission->fileAttachment()->firstOrFail();
        $this->assertDatabaseCount('assignment_submissions', 1);
        $this->assertDatabaseCount('assignment_submission_attachments', 1);
        $this->assertNotSame($firstPath, $newAttachment->path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($newAttachment->path);

        $this->actingAs($ctx['teacher']->user)->get(route('teacher.assignments.show', $assignment))
            ->assertOk()->assertSeeText($student->user->name)->assertSeeText('replacement.'.$extension);
        $this->get(route('teacher.submissions.file', $submission))
            ->assertOk()->assertDownload('replacement.'.$extension);
    }

    public static function unavailableAssignments(): array
    {
        return [['unselected'], ['no-class'], ['draft'], ['future'], ['cancelled']];
    }

    #[DataProvider('unavailableAssignments')]
    public function test_upload_cannot_bypass_assignment_access(string $reason): void
    {
        Storage::fake('local');
        $ctx = $this->context();
        if ($reason === 'unselected') {
            $ctx['assignment']->classrooms()->detach($ctx['student']->classroom_id);
        } elseif ($reason === 'no-class') {
            $ctx['student']->update(['classroom_id' => null]);
        } elseif ($reason === 'future') {
            $ctx['assignment']->update(['published_at' => now()->addHour()]);
        } else {
            $ctx['assignment']->update(['status' => $reason]);
        }

        $this->actingAs($ctx['student']->user)
            ->post(route('student.assignments.submit', $ctx['assignment']), ['file' => $this->file('denied.pdf')])
            ->assertNotFound();
        $this->assertDatabaseCount('assignment_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_expired_form_returns_to_the_homework_with_a_clear_error(): void
    {
        Storage::fake('local');
        $ctx = $this->context();
        $ctx['assignment']->update(['due_at' => now()->subMinute(), 'due_date' => null]);

        $this->actingAs($ctx['student']->user)->from('/student/dashboard')
            ->post(route('student.assignments.submit', $ctx['assignment']), ['file' => $this->file('late.pdf')])
            ->assertRedirect(route('student.assignments.show', $ctx['assignment']))
            ->assertSessionHasErrors(['file' => 'انتهى موعد تسليم الواجب.']);
        $this->assertDatabaseCount('assignment_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_graded_branch_submission_is_preserved_when_replacement_is_attempted(): void
    {
        Storage::fake('local');
        $ctx = $this->context();
        $this->upload($ctx, 'graded.pdf')->assertSessionHasNoErrors();
        $submission = AssignmentSubmission::firstOrFail();
        $path = $submission->fileAttachment->path;
        $submission->update(['score' => 8.5, 'graded_at' => now(), 'status' => 'graded']);

        $this->upload($ctx, 'replacement.pdf')->assertSessionHasErrors('file');
        $submission->refresh();
        $this->assertSame('8.50', $submission->score);
        $this->assertSame('graded', $submission->status);
        $this->assertSame($path, $submission->fileAttachment()->firstOrFail()->path);
        $this->assertSame([$path], Storage::disk('local')->allFiles());
    }

    public function test_service_rechecks_branch_access_after_the_form_was_opened(): void
    {
        Storage::fake('local');
        $ctx = $this->context();
        $this->assertTrue($ctx['assignment']->isAvailableToStudent($ctx['student']));
        $ctx['assignment']->classrooms()->detach($ctx['student']->classroom_id);
        try {
            app(AssignmentSubmissionService::class)->save($ctx['assignment'], $ctx['student'], $this->file('stale.pdf'), null);
            $this->fail('A removed branch must not be able to submit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertDatabaseCount('assignment_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function storageFailures(): array
    {
        return [['false'], ['exception']];
    }

    #[DataProvider('storageFailures')]
    public function test_storage_failure_returns_validation_without_losing_the_previous_submission(string $failure): void
    {
        $disk = Storage::fake('local');
        $ctx = $this->context();
        $this->upload($ctx, 'saved.pdf')->assertSessionHasNoErrors();
        $submission = AssignmentSubmission::firstOrFail();
        $path = $submission->fileAttachment->path;
        $storage = Mockery::mock(Filesystem::class);
        $write = $storage->shouldReceive('putFileAs')->once();
        if ($failure === 'exception') {
            $write->andThrow(new RuntimeException('Isolated storage write failure.'));
        } else {
            $write->andReturn(false);
        }
        Storage::shouldReceive('disk')->with('local')->andReturn($storage);

        $this->upload($ctx, 'failed.pdf')
            ->assertRedirect(route('student.assignments.show', $ctx['assignment']))
            ->assertSessionHasErrors('file');
        $submission->refresh();
        $this->assertSame('saved.pdf', $submission->original_name);
        $this->assertSame($path, $submission->fileAttachment()->firstOrFail()->path);
        $this->assertDatabaseCount('assignment_submissions', 1);
        $this->assertDatabaseCount('assignment_submission_attachments', 1);
        $this->assertTrue($disk->exists($path));
    }

    public function test_old_file_cleanup_failure_does_not_turn_a_successful_replacement_into_a_server_error(): void
    {
        $disk = Storage::fake('local');
        $ctx = $this->context();
        $this->upload($ctx, 'saved.pdf')->assertSessionHasNoErrors();
        $storage = Mockery::mock(Filesystem::class);
        $storage->shouldReceive('putFileAs')->once()->andReturnUsing(fn (...$arguments) => $disk->putFileAs(...$arguments));
        $storage->shouldReceive('delete')->once()->andThrow(new RuntimeException('Isolated cleanup failure.'));
        Storage::shouldReceive('disk')->with('local')->andReturn($storage);

        $this->upload($ctx, 'replacement.pdf')
            ->assertRedirect(route('student.assignments.show', $ctx['assignment']))
            ->assertSessionHasNoErrors();
        $submission = AssignmentSubmission::firstOrFail();
        $this->assertSame('replacement.pdf', $submission->original_name);
        $this->assertTrue($disk->exists($submission->fileAttachment->path));
        $this->assertDatabaseCount('assignment_submissions', 1);
    }

    private function upload(array $ctx, string $name)
    {
        return $this->actingAs($ctx['student']->user)
            ->from(route('student.assignments.show', $ctx['assignment']))
            ->post(route('student.assignments.submit', $ctx['assignment']), ['file' => $this->file($name)]);
    }

    private function file(string $name): UploadedFile
    {
        return str_ends_with($name, '.jpeg')
            ? UploadedFile::fake()->image($name, 40, 40)
            : UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    private function context(): array
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-07-01']);
        $primary = Classroom::create(['name' => 'الصف الأول', 'section' => 'A', 'stage' => 'Primary', 'academic_year_id' => $year->id]);
        $secondary = Classroom::create(['name' => 'الصف الأول', 'section' => 'B', 'stage' => 'Primary', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'English world', 'code' => 'HOMEWORK-EN', 'stage' => 'Primary', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['name' => 'معلمة الواجب', 'role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $student = Student::create(['user_id' => User::factory()->create(['name' => 'طالب الشعبة الثانية', 'role' => 'student', 'status' => 'active'])->id, 'student_number' => 'HOMEWORK-B', 'classroom_id' => $secondary->id, 'status' => 'active']);
        foreach ([$primary, $secondary] as $classroom) {
            $classroom->subjects()->attach($subject);
            DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
        }
        $assignment = Assignment::create(['title' => 'Reading - Grammar', 'instructions' => 'Complete the reading exercise.', 'subject_id' => $subject->id, 'classroom_id' => $primary->id, 'teacher_id' => $teacher->id, 'status' => 'active', 'published_at' => now()->subHour(), 'due_at' => now()->addDay()]);
        $assignment->classrooms()->sync([$primary->id, $secondary->id]);

        return compact('assignment', 'student', 'teacher', 'primary', 'secondary', 'subject');
    }
}
