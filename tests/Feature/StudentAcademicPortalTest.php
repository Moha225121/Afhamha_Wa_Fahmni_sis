<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ExamAttemptPolicy;
use App\Services\ExamAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentAcademicPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_assignments_for_own_class(): void
    {
        $own = $this->context('A1');
        $other = $this->context('A2');
        $this->assignment($own, ['title' => 'Own Assignment']);
        $this->assignment($other, ['title' => 'Foreign Assignment']);
        $this->actingAs($own['student']->user)->get(route('student.assignments.index'))
            ->assertOk()->assertSeeText('Own Assignment')->assertDontSeeText('Foreign Assignment');
    }

    public function test_student_cannot_see_unpublished_assignment(): void
    {
        $ctx = $this->context('A3');
        $draft = $this->assignment($ctx, ['title' => 'Draft Assignment', 'status' => 'draft']);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.index'))->assertOk()->assertDontSeeText('Draft Assignment');
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.show', $draft))->assertNotFound();
    }

    public function test_student_cannot_open_assignment_for_another_class(): void
    {
        $own = $this->context('A4');
        $other = $this->context('A5');
        $foreign = $this->assignment($other);
        $this->actingAs($own['student']->user)->get(route('student.assignments.show', $foreign))->assertNotFound();
    }

    public function test_invalid_submission_payload_cannot_probe_assignment_for_another_class(): void
    {
        $own = $this->context('A4C');
        $other = $this->context('A5C');
        $foreign = $this->assignment($other);
        $this->actingAs($own['student']->user)
            ->post(route('student.assignments.submit', $foreign), [])
            ->assertNotFound();
    }

    public function test_student_can_submit_assignment(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A6');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('answer.pdf'), 'notes' => 'done'])->assertRedirect();
        $submission = AssignmentSubmission::firstOrFail();
        $this->assertSame($ctx['student']->id, $submission->student_id);
        $this->assertNotNull($submission->submitted_at);
        $attachment = $submission->fileAttachment()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('assignment_submission_attachments', [
            'assignment_submission_id' => $submission->id,
            'path' => $attachment->path,
            'original_name' => 'answer.pdf',
        ]);
    }

    public function test_submission_is_persisted_after_refresh(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A7');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('persisted.pdf')]);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.show', $assignment))->assertOk()->assertSeeText('persisted.pdf')->assertSeeText('تم التسليم');
    }

    public function test_student_can_replace_own_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A8');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('first.pdf')]);
        $firstPath = AssignmentSubmission::firstOrFail()->fileAttachment()->firstOrFail()->path;
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('second.pdf')]);
        $submission = AssignmentSubmission::firstOrFail();
        $attachment = $submission->fileAttachment()->firstOrFail();
        $this->assertSame('second.pdf', $attachment->original_name);
        $this->assertNotSame($firstPath, $attachment->path);
        $this->assertDatabaseCount('assignment_submission_attachments', 1);
    }

    public function test_student_can_submit_assignment_without_deadline(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A8N');
        $assignment = $this->assignment($ctx, ['due_at' => null]);

        $this->actingAs($ctx['student']->user)
            ->get(route('student.assignments.show', $assignment))
            ->assertOk()
            ->assertSeeText('بدون موعد نهائي');
        $this->post(route('student.assignments.submit', $assignment), [
            'file' => $this->pdf('no-deadline.pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'student_id' => $ctx['student']->id,
        ]);
    }

    public function test_graded_submission_cannot_be_replaced_or_lose_its_manual_grade(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A8G');
        $assignment = $this->assignment($ctx);
        $submission = $this->submission($assignment, $ctx['student'], 'graded.pdf', 'graded.pdf');
        $gradedAt = now()->subHour()->startOfSecond();
        $submission->update(['score' => 8, 'graded_at' => $gradedAt]);

        $originalPath = $submission->fileAttachment->path;
        $this->actingAs($ctx['student']->user)->post(
            route('student.assignments.submit', $assignment),
            ['file' => $this->pdf('replacement.pdf')],
        )->assertSessionHasErrors('file');

        $submission->refresh();
        $this->assertSame('8.00', $submission->score);
        $this->assertTrue($submission->graded_at->equalTo($gradedAt));
        $this->assertSame('graded.pdf', $submission->fileAttachment()->firstOrFail()->original_name);
        Storage::disk('local')->assertExists($originalPath);
        $this->assertDatabaseCount('assignment_submission_attachments', 1);
    }

    public function test_student_cannot_replace_another_students_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A9');
        $other = $this->studentIn($ctx, 'A9B');
        $assignment = $this->assignment($ctx);
        Storage::disk('local')->put('other/file.pdf', 'other');
        $foreign = $this->submission($assignment, $other, 'other/file.pdf', 'other.pdf');
        $foreignPath = $foreign->fileAttachment->path;
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('mine.pdf')]);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $foreign->id, 'student_id' => $other->id]);
        $this->assertDatabaseHas('assignment_submission_attachments', [
            'assignment_submission_id' => $foreign->id,
            'path' => $foreignPath,
        ]);
        $this->assertDatabaseCount('assignment_submissions', 2);
    }

    public function test_student_cannot_download_another_students_file(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A10');
        $other = $this->studentIn($ctx, 'A10B');
        $assignment = $this->assignment($ctx);
        Storage::disk('local')->put('foreign.pdf', 'secret');
        $foreign = $this->submission($assignment, $other, 'foreign.pdf', 'foreign.pdf');
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.submission-file', $foreign))->assertNotFound();
    }

    public function test_historical_main_submission_with_nullable_file_metadata_remains_downloadable(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A10H');
        $assignment = $this->assignment($ctx);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $ctx['student']->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $path = "assignment-submissions/{$assignment->id}/{$ctx['student']->id}/legacy.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nhistorical submission");
        $submission->attachments()->create([
            'path' => $path,
            'original_name' => 'legacy.pdf',
            'mime_type' => null,
            'size' => null,
        ]);

        $this->actingAs($ctx['student']->user)
            ->get(route('student.assignments.show', $assignment))
            ->assertOk()
            ->assertSee(route('student.assignments.submission-file', $submission));
        $this->get(route('student.assignments.submission-file', $submission))
            ->assertOk()
            ->assertDownload('legacy.pdf');
        $this->actingAs($ctx['teacher']->user)
            ->get(route('teacher.submissions.file', $submission))
            ->assertOk()
            ->assertDownload('legacy.pdf');
    }

    public function test_historical_submission_fallback_rejects_public_absolute_and_traversal_paths(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A10HS');
        $assignment = $this->assignment($ctx);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $ctx['student']->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $attachment = $submission->attachments()->create([
            'disk' => 'public',
            'path' => 'historical/public.pdf',
            'original_name' => 'public.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $this->actingAs($ctx['student']->user)
            ->get(route('student.assignments.submission-file', $submission))
            ->assertNotFound();

        $attachment->update(['disk' => null, 'path' => 'C:/private/absolute.pdf']);
        $this->get(route('student.assignments.submission-file', $submission))->assertNotFound();

        $attachment->update(['path' => 'historical/../private.pdf']);
        $this->get(route('student.assignments.submission-file', $submission))->assertNotFound();

        Storage::disk('local')->put('library/unrelated-private.pdf', "%PDF-1.4\nunrelated private file");
        $attachment->update([
            'path' => 'library/unrelated-private.pdf',
            'mime_type' => null,
            'size' => null,
        ]);
        $this->get(route('student.assignments.submission-file', $submission))->assertNotFound();
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A11');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => UploadedFile::fake()->create('script.php', 2, 'application/x-php')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A12');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('assignment_submissions', 0);
    }

    public function test_old_file_is_removed_after_successful_replacement(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A13');
        $assignment = $this->assignment($ctx);
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('old.pdf')]);
        $oldPath = AssignmentSubmission::firstOrFail()->fileAttachment()->firstOrFail()->path;
        $this->actingAs($ctx['student']->user)->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('new.pdf')]);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists(AssignmentSubmission::firstOrFail()->fileAttachment()->firstOrFail()->path);
    }

    public function test_assignment_can_have_multiple_attachments(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM1');
        $assignment = $this->assignment($ctx);
        $this->attachment($assignment, 'instructions.pdf', 1);
        $this->attachment($assignment, 'worksheet.pdf', 2);
        $this->assertCount(2, $assignment->fresh()->attachments);
        $this->assertSame(['instructions.pdf', 'worksheet.pdf'], $assignment->fresh()->attachments->pluck('original_name')->all());
    }

    public function test_student_can_view_all_attachments_for_own_assignment(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM2');
        $assignment = $this->assignment($ctx);
        $this->attachment($assignment, 'first.pdf', 1);
        $this->attachment($assignment, 'second.pdf', 2);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.show', $assignment))->assertOk()->assertSeeText('first.pdf')->assertSeeText('second.pdf');
    }

    public function test_student_can_download_attachment_for_own_class(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM3');
        $assignment = $this->assignment($ctx);
        $attachment = $this->attachment($assignment, 'own.pdf');
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.attachments.file', $attachment))->assertOk()->assertDownload('own.pdf');
    }

    public function test_student_cannot_download_attachment_for_another_class(): void
    {
        Storage::fake('local');
        $own = $this->context('AM4');
        $other = $this->context('AM5');
        $attachment = $this->attachment($this->assignment($other), 'foreign.pdf');
        $this->actingAs($own['student']->user)->get(route('student.assignments.attachments.file', $attachment))->assertNotFound();
    }

    public function test_unpublished_assignment_attachment_is_not_accessible(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM6');
        $assignment = $this->assignment($ctx, ['status' => 'draft']);
        $attachment = $this->attachment($assignment, 'draft.pdf');
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.attachments.file', $attachment))->assertNotFound();
    }

    public function test_attachment_download_uses_private_storage(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $ctx = $this->context('AM7');
        $assignment = $this->assignment($ctx);
        $attachment = $this->attachment($assignment, 'private.pdf');
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.show', $assignment))->assertOk()->assertSee(route('student.assignments.attachments.file', $attachment))->assertDontSee('/storage/'.$attachment->path);
        Storage::disk('public')->put("assignment-attachments/{$assignment->id}/public.pdf", 'public');
        $publicAttachment = AssignmentAttachment::create(['assignment_id' => $assignment->id, 'disk' => 'public', 'path' => "assignment-attachments/{$assignment->id}/public.pdf", 'original_name' => 'public.pdf', 'mime_type' => 'application/pdf', 'size' => 6]);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.attachments.file', $publicAttachment))->assertNotFound();
    }

    public function test_historical_main_assignment_attachment_remains_downloadable(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM8');
        $assignment = $this->assignment($ctx);
        $path = "assignment-attachments/{$assignment->id}/legacy.pdf";
        Storage::disk('local')->put($path, "%PDF-1.4\nhistorical assignment");
        $attachment = AssignmentAttachment::create([
            'assignment_id' => $assignment->id,
            'path' => $path,
            'original_name' => 'legacy.pdf',
            'mime_type' => null,
            'size' => null,
        ]);

        $this->actingAs($ctx['student']->user)->get(route('student.assignments.attachment', $assignment))
            ->assertOk()
            ->assertDownload($attachment->original_name);
    }

    public function test_invalid_attachment_metadata_and_traversal_paths_are_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AM9');
        $assignment = $this->assignment($ctx);
        $base = ['assignment_id' => $assignment->id, 'disk' => 'local', 'original_name' => 'unsafe.pdf', 'mime_type' => 'application/pdf', 'size' => 4];
        Storage::disk('local')->put("assignment-attachments/{$assignment->id}/mime.pdf", 'file');
        $badMime = AssignmentAttachment::create(array_merge($base, ['path' => "assignment-attachments/{$assignment->id}/mime.pdf", 'mime_type' => 'application/x-php']));
        Storage::disk('local')->put("assignment-attachments/{$assignment->id}/large.pdf", 'file');
        $oversized = AssignmentAttachment::create(array_merge($base, ['path' => "assignment-attachments/{$assignment->id}/large.pdf", 'size' => 10 * 1024 * 1024 + 1]));
        $traversal = AssignmentAttachment::create(array_merge($base, ['path' => "assignment-attachments/{$assignment->id}/../secret.pdf"]));
        $absolute = AssignmentAttachment::create(array_merge($base, ['disk' => null, 'path' => 'C:/private/secret.pdf']));
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.attachments.file', $badMime))->assertNotFound();
        $this->get(route('student.assignments.attachments.file', $oversized))->assertNotFound();
        $this->get(route('student.assignments.attachments.file', $traversal))->assertNotFound();
        $this->get(route('student.assignments.attachments.file', $absolute))->assertNotFound();
    }

    public function test_submission_without_submitted_at_is_not_reported_as_delivered(): void
    {
        $ctx = $this->context('AS0');
        $assignment = $this->assignment($ctx);
        AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $ctx['student']->id,
            'status' => 'submitted',
            'submitted_at' => null,
        ]);

        $this->actingAs($ctx['student']->user)
            ->get(route('student.assignments.index'))
            ->assertOk()
            ->assertSeeText('متاح · لم يسلّم')
            ->assertDontSeeText('تم التسليم');
        $this->get(route('student.assignments.show', $assignment))
            ->assertOk()
            ->assertSeeText('لم يسلّم')
            ->assertSeeText('تسليم الواجب')
            ->assertDontSeeText('استبدال التسليم');

        $this->actingAs($ctx['teacher']->user)
            ->get(route('teacher.assignments.index'))
            ->assertOk()
            ->assertSeeText('0 تسليم');
        $this->get(route('teacher.assignments.show', $assignment))
            ->assertOk()
            ->assertSeeText('لم يستلم هذا الواجب أي تسليم بعد.');
    }

    public function test_authorized_teacher_can_view_student_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('A14');
        $assignment = $this->assignment($ctx);
        Storage::disk('local')->put('teacher-visible.pdf', 'work');
        $this->submission($assignment, $ctx['student'], 'teacher-visible.pdf', 'teacher-visible.pdf');
        $this->actingAs($ctx['teacher']->user)->get(route('teacher.assignments.show', $assignment))->assertOk()->assertSeeText($ctx['student']->user->name)->assertSeeText('teacher-visible.pdf');
    }

    public function test_unrelated_teacher_cannot_view_student_submission(): void
    {
        $ctx = $this->context('A15');
        $unrelated = $this->context('A16');
        $assignment = $this->assignment($ctx);
        $this->actingAs($unrelated['teacher']->user)->get(route('teacher.assignments.show', $assignment))->assertNotFound();
    }

    public function test_assigned_teacher_can_view_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AT1');
        $differentOwner = $this->context('AT2');
        $assignment = $this->assignment($ctx, ['teacher_id' => $differentOwner['teacher']->id]);
        Storage::disk('local')->put('assigned/view.pdf', 'work');
        $this->submission($assignment, $ctx['student'], 'assigned/view.pdf', 'view.pdf');
        $this->actingAs($ctx['teacher']->user)->get(route('teacher.assignments.show', $assignment))->assertOk()->assertSeeText('view.pdf');
    }

    public function test_assigned_teacher_can_download_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AT3');
        $assignment = $this->assignment($ctx);
        Storage::disk('local')->put('assigned/download.pdf', 'work');
        $submission = $this->submission($assignment, $ctx['student'], 'assigned/download.pdf', 'download.pdf');
        $this->actingAs($ctx['teacher']->user)->get(route('teacher.submissions.file', $submission))->assertOk()->assertDownload('download.pdf');
    }

    public function test_unrelated_teacher_cannot_download_submission(): void
    {
        Storage::fake('local');
        $ctx = $this->context('AT4');
        $unrelated = $this->context('AT5');
        $assignment = $this->assignment($ctx);
        Storage::disk('local')->put('assigned/secret.pdf', 'work');
        $submission = $this->submission($assignment, $ctx['student'], 'assigned/secret.pdf', 'secret.pdf');
        $this->actingAs($unrelated['teacher']->user)->get(route('teacher.submissions.file', $submission))->assertNotFound();
    }

    public function test_teacher_integration_does_not_expose_other_classes(): void
    {
        $ctx = $this->context('AT6');
        $other = $this->context('AT7');
        $this->assignment($ctx, ['title' => 'Assigned Class Work']);
        $this->assignment($other, ['title' => 'Other Class Work']);
        $this->actingAs($ctx['teacher']->user)->get(route('teacher.assignments.index'))->assertOk()->assertSeeText('Assigned Class Work')->assertDontSeeText('Other Class Work');
    }

    public function test_unrelated_teacher_cannot_view_submission(): void
    {
        $ctx = $this->context('AT8');
        $unrelated = $this->context('AT9');
        $assignment = $this->assignment($ctx);
        $this->actingAs($unrelated['teacher']->user)->get(route('teacher.assignments.show', $assignment))->assertNotFound();
    }

    public function test_student_sees_only_published_exams_for_own_class(): void
    {
        $own = $this->context('E1');
        $other = $this->context('E2');
        $this->exam($own, ['title' => 'Own Published']);
        $this->exam($own, ['title' => 'Own Draft', 'status' => 'draft']);
        $this->exam($other, ['title' => 'Foreign Published']);
        $this->actingAs($own['student']->user)->get(route('student.exams.index'))->assertOk()->assertSeeText('Own Published')->assertDontSeeText('Own Draft')->assertDontSeeText('Foreign Published');
    }

    public function test_student_cannot_start_exam_before_start_time(): void
    {
        $ctx = $this->context('E3');
        $exam = $this->exam($ctx, ['starts_at' => now()->addDay()->setTime(9, 0)]);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertStatus(422);
        $this->assertDatabaseCount('exam_attempts', 0);
    }

    public function test_student_can_start_exam_when_it_is_scheduled_for_today(): void
    {
        $ctx = $this->context('E3A');
        $exam = $this->exam($ctx, ['starts_at' => now()->copy()->addMinutes(5), 'duration_minutes' => 30]);
        $this->question($exam);

        $this->actingAs($ctx['student']->user)
            ->post(route('student.exams.start', $exam))
            ->assertRedirect();

        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $exam->id,
            'student_id' => $ctx['student']->id,
        ]);
    }

    public function test_student_cannot_start_exam_after_end_time(): void
    {
        $ctx = $this->context('E4');
        $exam = $this->exam($ctx, ['starts_at' => now()->subMinutes(31), 'duration_minutes' => 30]);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertStatus(422);
        $this->assertDatabaseCount('exam_attempts', 0);
    }

    public function test_student_cannot_start_exam_for_another_class(): void
    {
        $own = $this->context('E5');
        $other = $this->context('E6');
        $exam = $this->exam($other);
        $this->actingAs($own['student']->user)->post(route('student.exams.start', $exam))->assertNotFound();
    }

    public function test_starting_exam_creates_attempt_for_authenticated_student(): void
    {
        $ctx = $this->context('E7');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertRedirect();
        $this->assertDatabaseHas('exam_attempts', ['exam_id' => $exam->id, 'student_id' => $ctx['student']->id, 'attempt_number' => 1, 'status' => 'in_progress', 'maximum_score' => 10]);
        $this->assertDatabaseCount('exam_answers', 1);
    }

    public function test_duplicate_start_does_not_create_invalid_duplicate_attempt(): void
    {
        $ctx = $this->context('E8');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam));
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertRedirect();
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_answers', 1);
    }

    public function test_current_default_policy_allows_one_attempt(): void
    {
        $ctx = $this->context('EP1');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $service = app(ExamAttemptService::class);
        $this->assertSame(1, app(ExamAttemptPolicy::class)->maximumAttempts($exam));
        $first = $service->start($exam, $ctx['student']);
        $service->finalize($first);
        $second = $service->start($exam, $ctx['student']);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('exam_attempts', 1);
    }

    public function test_duplicate_start_request_does_not_create_second_attempt(): void
    {
        $ctx = $this->context('EP2');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertRedirect();
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam))->assertRedirect();
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_answers', 1);
    }

    public function test_concurrent_attempt_creation_is_protected(): void
    {
        $ctx = $this->context('EP4');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $service = app(ExamAttemptService::class);
        $first = $service->start($exam, $ctx['student']);
        $second = $service->start($exam, $ctx['student']);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('exam_attempts', 1);
        $indexes = Schema::getIndexes('exam_attempts');
        $hasUniqueAttemptKey = collect($indexes)->contains(fn (array $index): bool => ($index['unique'] ?? false)
            && ($index['columns'] ?? []) === ['exam_id', 'student_id', 'attempt_number']);
        $this->assertTrue($hasUniqueAttemptKey, 'A unique exam/student/attempt-number key must protect concurrent inserts.');
    }

    public function test_attempt_limit_is_applied_from_central_policy(): void
    {
        config()->set('student_academic.exams.default_attempt_limit', 2);
        $ctx = $this->context('EP3');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $service = app(ExamAttemptService::class);
        $first = $service->start($exam, $ctx['student']);
        $service->finalize($first);
        $second = $service->start($exam, $ctx['student']);
        $service->finalize($second);
        $third = $service->start($exam, $ctx['student']);
        $this->assertSame(1, $first->attempt_number);
        $this->assertSame(2, $second->attempt_number);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $third->id);
        $this->assertDatabaseCount('exam_attempts', 2);
    }

    public function test_attempt_policy_ui_offers_an_approved_additional_attempt(): void
    {
        config()->set('student_academic.exams.default_attempt_limit', 2);
        $ctx = $this->context('EP5');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        app(ExamAttemptService::class)->finalize($attempt);
        $this->actingAs($ctx['student']->user)->get(route('student.exams.index'))->assertOk()->assertSeeText('ابدأ محاولة جديدة');
    }

    public function test_student_cannot_open_another_students_attempt(): void
    {
        $ctx = $this->context('E9');
        $other = $this->studentIn($ctx, 'E9B');
        $exam = $this->exam($ctx);
        $this->question($exam);
        $attempt = app(ExamAttemptService::class)->start($exam, $other);
        $this->actingAs($ctx['student']->user)->get(route('student.exams.attempt', $attempt))->assertNotFound();
    }

    public function test_invalid_answer_payload_cannot_probe_another_students_attempt(): void
    {
        $ctx = $this->context('E9C');
        $other = $this->studentIn($ctx, 'E9D');
        $exam = $this->exam($ctx);
        $question = $this->question($exam);
        $attempt = app(ExamAttemptService::class)->start($exam, $other);
        $this->actingAs($ctx['student']->user)
            ->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => []])
            ->assertNotFound();
    }

    public function test_student_can_save_answer_during_active_attempt(): void
    {
        [$ctx, $exam, $question, $attempt] = $this->activeAttempt('Q1');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4'])->assertRedirect();
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id, 'answer' => '4']);
    }

    public function test_saved_answer_is_restored_after_refresh(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('Q2');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->get(route('student.exams.attempt', $attempt))->assertOk()->assertSee('value="4" checked', false);
    }

    public function test_student_cannot_answer_question_from_another_exam(): void
    {
        [$ctx, , , $attempt] = $this->activeAttempt('Q3');
        $otherExam = $this->exam($ctx, ['title' => 'Other']);
        $foreignQuestion = $this->question($otherExam);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $foreignQuestion]), ['answer' => '4'])->assertNotFound();
    }

    public function test_student_cannot_select_option_from_another_question(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('Q4');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => 'foreign-option'])->assertSessionHasErrors('answer');
        $this->assertDatabaseMissing('exam_answers', ['exam_attempt_id' => $attempt->id, 'answer' => 'foreign-option']);
    }

    public function test_student_cannot_save_answer_after_expiration(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('Q5');
        $attempt->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4'])->assertRedirect(route('student.exams.result', $attempt));
        $this->assertDatabaseMissing('exam_answers', ['exam_attempt_id' => $attempt->id, 'answer' => '4']);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'submitted']);
    }

    public function test_server_time_controls_exam_expiration(): void
    {
        $this->travelTo(Carbon::parse('2026-08-23 10:00:00'));
        $ctx = $this->context('Q6');
        $exam = $this->exam($ctx, ['starts_at' => now(), 'duration_minutes' => 20]);
        $this->question($exam);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.start', $exam));
        $attempt = ExamAttempt::firstOrFail();
        $this->assertTrue($attempt->expires_at->equalTo(Carbon::parse('2026-08-23 10:20:00')));
    }

    public function test_refreshing_page_does_not_reset_timer(): void
    {
        [$ctx, , , $attempt] = $this->activeAttempt('Q7');
        $expiresAt = $attempt->expires_at->toDateTimeString();
        $this->travel(2)->minutes();
        $this->actingAs($ctx['student']->user)->get(route('student.exams.attempt', $attempt))->assertOk();
        $this->assertSame($expiresAt, $attempt->fresh()->expires_at->toDateTimeString());
    }

    public function test_attempt_page_does_not_expose_answer_key(): void
    {
        [$ctx, , , $attempt] = $this->activeAttempt('Q8');
        $this->actingAs($ctx['student']->user)->get(route('student.exams.attempt', $attempt))->assertOk()->assertDontSee('correct_answer')->assertDontSee('correct_answer_snapshot')->assertDontSee('is_correct');
    }

    public function test_grading_uses_attempt_snapshot_when_original_question_changes(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('Q9');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $question->update(['correct_answer' => '3', 'score' => 1]);
        app(ExamAttemptService::class)->finalize($attempt);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'submitted', 'score' => 10, 'maximum_score' => 10, 'percentage' => 100]);
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attempt->id, 'correct_answer_snapshot' => '4', 'max_score' => 10, 'is_correct' => true]);
    }

    public function test_submitting_attempt_grades_objective_questions(): void
    {
        [$ctx, $exam, $question, $attempt] = $this->activeAttempt('G1');
        $second = $this->question($exam, ['question_text' => '3+3?', 'options' => ['5' => '5', '6' => '6'], 'correct_answer' => '6', 'score' => 5, 'position' => 2]);
        $exam->update(['total_score' => 15]);
        $attempt->update(['maximum_score' => 15]);
        $this->snapshotQuestion($attempt, $second);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $second]), ['answer' => '5']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt))->assertRedirect(route('student.exams.result', $attempt));
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'score' => 10, 'status' => 'submitted']);
    }

    public function test_score_and_percentage_are_persisted(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('G2');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'score' => 10, 'maximum_score' => 10, 'percentage' => 100, 'status' => 'submitted']);
    }

    public function test_expired_attempt_is_finalized_and_graded(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('G3');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $attempt->update(['expires_at' => now()->subSecond()]);
        $this->artisan('student-exams:finalize-expired')->assertSuccessful();
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'submitted', 'score' => 10, 'percentage' => 100]);
    }

    public function test_duplicate_submit_does_not_duplicate_score(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('G4');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $submittedAt = $attempt->fresh()->submitted_at->toDateTimeString();
        $this->travel(1)->minute();
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->assertSame($submittedAt, $attempt->fresh()->submitted_at->toDateTimeString());
        $this->assertSame('10.00', $attempt->fresh()->score);
    }

    public function test_automatic_grading_does_not_modify_manual_teacher_grade(): void
    {
        [$ctx, $exam, $question, $attempt] = $this->activeAttempt('GM1');
        DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $ctx['student']->id, 'score' => 7, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $manualUpdatedAt = DB::table('grades')->where('exam_id', $exam->id)->value('updated_at');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->assertDatabaseHas('grades', ['exam_id' => $exam->id, 'student_id' => $ctx['student']->id, 'score' => 7]);
        $this->assertSame((string) $manualUpdatedAt, (string) DB::table('grades')->where('exam_id', $exam->id)->value('updated_at'));
        $this->assertDatabaseCount('grades', 1);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'score' => 10, 'percentage' => 100]);
    }

    public function test_attempt_with_essay_question_is_marked_pending_review(): void
    {
        $ctx = $this->context('ES1');
        $exam = $this->exam($ctx);
        $question = $this->question($exam, ['type' => 'essay', 'options' => null, 'correct_answer' => null]);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => 'إجابة مقالية']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt))->assertRedirect(route('student.exams.result', $attempt));
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'pending_review', 'score' => 0, 'percentage' => null, 'graded_at' => null]);
    }

    public function test_mixed_exam_saves_objective_score_without_publishing_false_final_result(): void
    {
        $ctx = $this->context('ES2');
        $exam = $this->exam($ctx, ['total_score' => 10]);
        $objective = $this->question($exam, ['score' => 4]);
        $essay = $this->question($exam, ['question_text' => 'اشرح الحل', 'type' => 'essay', 'options' => null, 'correct_answer' => null, 'score' => 6, 'position' => 2]);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $objective]), ['answer' => '4']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $essay]), ['answer' => 'شرح الطالب']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'pending_review', 'score' => 4, 'maximum_score' => 10, 'percentage' => null, 'graded_at' => null]);
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attempt->id, 'exam_question_id' => $objective->id, 'is_correct' => true, 'awarded_score' => 4]);
        $this->actingAs($ctx['student']->user)->get(route('student.exams.result', $attempt))->assertOk()->assertSeeText('بانتظار المراجعة')->assertDontSeeText('4 / 10')->assertDontSeeText('40%');
    }

    public function test_essay_only_exam_does_not_publish_zero_as_final_grade(): void
    {
        $ctx = $this->context('ES3');
        $exam = $this->exam($ctx);
        $this->question($exam, ['type' => 'essay', 'options' => null, 'correct_answer' => null]);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        app(ExamAttemptService::class)->finalize($attempt);
        $this->actingAs($ctx['student']->user)->get(route('student.exams.result', $attempt))->assertOk()->assertSeeText('بانتظار المراجعة')->assertDontSeeText('0 / 10')->assertDontSeeText('0%');
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'pending_review', 'score' => 0, 'percentage' => null, 'graded_at' => null]);
    }

    public function test_pending_review_attempt_cannot_be_modified(): void
    {
        $ctx = $this->context('ES4');
        $exam = $this->exam($ctx);
        $question = $this->question($exam, ['type' => 'essay', 'options' => null, 'correct_answer' => null]);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => 'النص الأول']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => 'نص معدل'])->assertRedirect(route('student.exams.result', $attempt));
        $this->assertDatabaseHas('exam_answers', ['exam_attempt_id' => $attempt->id, 'answer' => 'النص الأول']);
        $this->assertDatabaseMissing('exam_answers', ['exam_attempt_id' => $attempt->id, 'answer' => 'نص معدل']);
    }

    public function test_duplicate_submit_does_not_regrade_pending_review_attempt(): void
    {
        $ctx = $this->context('ES5');
        $exam = $this->exam($ctx);
        $this->question($exam, ['type' => 'essay', 'options' => null, 'correct_answer' => null]);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $first = $attempt->fresh();
        $submittedAt = $first->submitted_at->toDateTimeString();
        $this->travel(2)->minutes();
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $second = $attempt->fresh();
        $this->assertSame('pending_review', $second->status);
        $this->assertSame($submittedAt, $second->submitted_at->toDateTimeString());
        $this->assertSame('0.00', $second->score);
        $this->assertNull($second->graded_at);
        $this->assertNull($second->percentage);
    }

    public function test_finalize_expired_command_finalizes_due_attempts(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('SC1');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4']);
        $attempt->update(['expires_at' => now()]);
        $this->artisan('student-exams:finalize-expired')->expectsOutput('1 expired attempt(s) finalized.')->assertSuccessful();
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'submitted', 'score' => 10]);
    }

    public function test_finalize_expired_command_ignores_active_attempts(): void
    {
        [, , , $attempt] = $this->activeAttempt('SC2');
        $attempt->update(['expires_at' => now()->addMinute()]);
        $this->artisan('student-exams:finalize-expired')->expectsOutput('0 expired attempt(s) finalized.')->assertSuccessful();
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'status' => 'in_progress', 'submitted_at' => null]);
    }

    public function test_already_finalized_attempt_is_not_graded_twice(): void
    {
        [, , , $attempt] = $this->activeAttempt('SC3');
        $first = app(ExamAttemptService::class)->finalize($attempt);
        $first->update(['expires_at' => now()->subMinute()]);
        $submittedAt = $first->submitted_at->toDateTimeString();
        $gradedAt = $first->graded_at->toDateTimeString();
        $this->travel(1)->minute();
        $this->artisan('student-exams:finalize-expired')->expectsOutput('0 expired attempt(s) finalized.')->assertSuccessful();
        $current = $first->fresh();
        $this->assertSame($submittedAt, $current->submitted_at->toDateTimeString());
        $this->assertSame($gradedAt, $current->graded_at->toDateTimeString());
        $this->assertSame('0.00', $current->score);
    }

    public function test_scheduler_uses_server_expiration_time(): void
    {
        $this->travelTo(Carbon::parse('2026-08-23 12:00:00'));
        [, , , $due] = $this->activeAttempt('SC4');
        [, , , $active] = $this->activeAttempt('SC5');
        $due->update(['expires_at' => now()]);
        $active->update(['expires_at' => now()->addSecond()]);
        $this->artisan('student-exams:finalize-expired')->expectsOutput('1 expired attempt(s) finalized.')->assertSuccessful();
        $this->assertSame('submitted', $due->fresh()->status);
        $this->assertSame('in_progress', $active->fresh()->status);
    }

    public function test_answers_cannot_be_changed_after_submission(): void
    {
        [$ctx, , $question, $attempt] = $this->activeAttempt('G5');
        $this->actingAs($ctx['student']->user)->post(route('student.exams.submit', $attempt));
        $this->actingAs($ctx['student']->user)->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4'])->assertRedirect(route('student.exams.result', $attempt));
        $this->assertDatabaseMissing('exam_answers', ['exam_attempt_id' => $attempt->id, 'answer' => '4']);
    }

    public function test_result_belongs_only_to_authenticated_student(): void
    {
        [$ctx, , , $attempt] = $this->activeAttempt('G6');
        app(ExamAttemptService::class)->finalize($attempt);
        $other = $this->studentIn($ctx, 'G6B');
        $this->actingAs($other->user)->get(route('student.exams.result', $attempt))->assertNotFound();
    }

    public function test_result_remains_available_after_new_login(): void
    {
        [$ctx, , , $attempt] = $this->activeAttempt('G7');
        app(ExamAttemptService::class)->finalize($attempt);
        $this->actingAs($ctx['student']->user)->post(route('logout'));
        $this->post(route('login.store'), ['email' => $ctx['student']->user->email, 'password' => 'password123'])->assertRedirect();
        $this->get(route('student.exams.result', $attempt))->assertOk()->assertSeeText('نتيجة الاختبار');
    }

    public function test_attendance_page_contains_only_current_student_records(): void
    {
        $ctx = $this->context('P1');
        $other = $this->studentIn($ctx, 'P1B');
        $this->attendance($ctx, $ctx['student'], 'present', '2026-08-20');
        $this->attendance($ctx, $other, 'absent', '2026-08-21');
        $this->actingAs($ctx['student']->user)->get(route('student.attendance'))->assertOk()->assertSeeText('2026-08-20')->assertDontSeeText('2026-08-21');
    }

    public function test_schedule_page_contains_only_current_students_class(): void
    {
        $ctx = $this->context('P2');
        $other = $this->context('P3');
        $this->schedule($ctx, 0, '08:00');
        $this->schedule($other, 1, '09:00');
        $this->actingAs($ctx['student']->user)->get(route('student.schedule'))->assertOk()->assertSeeText($ctx['subject']->name)->assertSeeText('الأحد')->assertDontSeeText($other['subject']->name);
    }

    public function test_notifications_are_filtered_for_current_student(): void
    {
        $ctx = $this->context('P4');
        $other = $this->context('P5');
        $this->notification($ctx['student']->user, 'My Notice');
        $this->notification($other['student']->user, 'Foreign Notice');
        Announcement::create(['title' => 'My Class', 'content' => 'class news', 'audience' => 'classroom', 'classroom_id' => $ctx['classroom']->id, 'status' => 'published', 'published_at' => now(), 'created_by' => $ctx['teacher']->user_id]);
        Announcement::create(['title' => 'Parent Only', 'content' => 'private', 'audience' => 'parents', 'classroom_id' => $ctx['classroom']->id, 'status' => 'published', 'published_at' => now(), 'created_by' => $ctx['teacher']->user_id]);
        $this->actingAs($ctx['student']->user)->get(route('student.notifications'))->assertOk()->assertSeeText('My Notice')->assertSeeText('My Class')->assertDontSeeText('Foreign Notice')->assertDontSeeText('Parent Only');
    }

    public function test_student_cannot_mark_another_students_notification_as_read(): void
    {
        $ctx = $this->context('P6');
        $other = $this->context('P7');
        $id = $this->notification($other['student']->user, 'Foreign');
        $this->actingAs($ctx['student']->user)->patch(route('student.notifications.read', $id))->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_complete_student_academic_scenario(): void
    {
        Storage::fake('local');
        $ctx = $this->context('FULL');
        $assignment = $this->assignment($ctx, ['title' => 'Scenario Assignment']);
        $exam = $this->exam($ctx, ['title' => 'Scenario Exam']);
        $question = $this->question($exam);
        $this->actingAs($ctx['student']->user)->get(route('student.assignments.index'))->assertSeeText('Scenario Assignment');
        $this->post(route('student.assignments.submit', $assignment), ['file' => $this->pdf('scenario.pdf')])->assertRedirect();
        $this->post(route('student.exams.start', $exam))->assertRedirect();
        $attempt = ExamAttempt::firstOrFail();
        $this->post(route('student.exams.answers.save', [$attempt, $question]), ['answer' => '4'])->assertRedirect();
        $this->post(route('student.exams.submit', $attempt))->assertRedirect(route('student.exams.result', $attempt));
        $this->get(route('student.exams.result', $attempt))->assertOk()->assertSeeText('100%');
        $this->assertDatabaseHas('assignment_submissions', ['assignment_id' => $assignment->id, 'student_id' => $ctx['student']->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'score' => 10, 'maximum_score' => 10, 'percentage' => 100]);
    }

    private function context(string $suffix): array
    {
        $year = AcademicYear::create(['name' => "2026-$suffix", 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $classroom = Classroom::create(['name' => "Class $suffix", 'stage' => 'Primary', 'section' => $suffix, 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => "Subject $suffix", 'code' => "SUB-$suffix", 'stage' => 'Primary', 'status' => 'active']);
        $teacherUser = User::factory()->create(['name' => "Teacher $suffix", 'role' => 'teacher', 'status' => 'active', 'password' => 'password123']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $studentUser = User::factory()->create(['name' => "Student $suffix", 'role' => 'student', 'status' => 'active', 'password' => 'password123']);
        $student = Student::create(['user_id' => $studentUser->id, 'student_number' => "S-$suffix", 'classroom_id' => $classroom->id, 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);

        return compact('year', 'classroom', 'subject', 'teacher', 'student');
    }

    private function studentIn(array $ctx, string $suffix): Student
    {
        $user = User::factory()->create(['name' => "Student $suffix", 'role' => 'student', 'status' => 'active', 'password' => 'password123']);

        return Student::create(['user_id' => $user->id, 'student_number' => "S-$suffix", 'classroom_id' => $ctx['classroom']->id, 'status' => 'active']);
    }

    private function assignment(array $ctx, array $overrides = []): Assignment
    {
        return Assignment::create(array_merge(['title' => 'Homework', 'instructions' => 'Complete the assigned work.', 'subject_id' => $ctx['subject']->id, 'classroom_id' => $ctx['classroom']->id, 'teacher_id' => $ctx['teacher']->id, 'due_at' => now()->addDay(), 'status' => 'published', 'published_at' => now()], $overrides));
    }

    private function submission(Assignment $assignment, Student $student, string $path, string $name): AssignmentSubmission
    {
        $storedPath = "assignment-submissions/{$assignment->id}/{$student->id}/".basename($path);
        $contents = Storage::disk('local')->exists($path)
            ? Storage::disk('local')->get($path)
            : '%PDF-1.4 submission';
        Storage::disk('local')->put($storedPath, $contents);
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
        $attachment = $submission->attachments()->create([
            'disk' => 'local',
            'path' => $storedPath,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
        ]);

        return $submission->setRelation('fileAttachment', $attachment);
    }

    private function attachment(Assignment $assignment, string $name, int $sortOrder = 0): AssignmentAttachment
    {
        $path = "assignment-attachments/{$assignment->id}/".Str::uuid().'.pdf';
        $contents = '%PDF-1.4 private attachment';
        Storage::disk('local')->put($path, $contents);

        return AssignmentAttachment::create([
            'assignment_id' => $assignment->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'sort_order' => $sortOrder,
        ]);
    }

    private function exam(array $ctx, array $overrides = []): Exam
    {
        return Exam::create(array_merge(['title' => 'Quiz', 'subject_id' => $ctx['subject']->id, 'classroom_id' => $ctx['classroom']->id, 'teacher_id' => $ctx['teacher']->id, 'starts_at' => now()->subMinute(), 'duration_minutes' => 30, 'total_score' => 10, 'status' => 'published'], $overrides));
    }

    private function question(Exam $exam, array $overrides = []): ExamQuestion
    {
        return ExamQuestion::create(array_merge(['exam_id' => $exam->id, 'question_text' => '2+2?', 'type' => 'multiple_choice', 'options' => ['3' => '3', '4' => '4'], 'correct_answer' => '4', 'score' => 10, 'position' => 1], $overrides));
    }

    private function activeAttempt(string $suffix): array
    {
        $ctx = $this->context($suffix);
        $exam = $this->exam($ctx);
        $question = $this->question($exam);
        $attempt = app(ExamAttemptService::class)->start($exam, $ctx['student']);

        return [$ctx, $exam, $question, $attempt];
    }

    private function snapshotQuestion(ExamAttempt $attempt, ExamQuestion $question): void
    {
        ExamAnswer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id, 'question_text_snapshot' => $question->question_text, 'question_type_snapshot' => $question->type, 'options_snapshot' => $question->options, 'correct_answer_snapshot' => $question->correct_answer, 'max_score' => $question->score]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    private function attendance(array $ctx, Student $student, string $status, string $date): void
    {
        DB::table('attendance_records')->insert(['student_id' => $student->id, 'classroom_id' => $ctx['classroom']->id, 'date' => $date, 'status' => $status, 'recorded_by' => $ctx['teacher']->user_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function schedule(array $ctx, int $day, string $starts): void
    {
        DB::table('schedules')->insert(['classroom_id' => $ctx['classroom']->id, 'teacher_id' => $ctx['teacher']->id, 'subject_id' => $ctx['subject']->id, 'day_of_week' => $day, 'starts_at' => $starts, 'ends_at' => Carbon::parse($starts)->addHour()->format('H:i'), 'room' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notification(User $user, string $title): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => json_encode(['title' => $title, 'message' => 'body']), 'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
