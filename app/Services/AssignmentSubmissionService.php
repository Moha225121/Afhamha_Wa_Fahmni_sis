<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\Student;
use App\Support\AssignmentDeadline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AssignmentSubmissionService
{
    public function save(Assignment $assignment, Student $student, UploadedFile $file, ?string $notes): AssignmentSubmission
    {
        try {
            $path = $file->store("assignment-submissions/{$assignment->id}/{$student->id}", 'local');
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'تعذر حفظ ملف التسليم. يرجى المحاولة مرة أخرى.'])
                ->redirectTo(route('student.assignments.show', $assignment, false));
        }
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ ملف التسليم. يرجى المحاولة مرة أخرى.'])
                ->redirectTo(route('student.assignments.show', $assignment, false));
        }

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $originalName = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $originalName) ?: 'submission';
        $originalName = Str::limit($originalName, 255, '');

        try {
            [$submission, $oldFile] = DB::transaction(function () use ($assignment, $student, $path, $originalName, $file, $notes): array {
                $lockedAssignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
                if (! $lockedAssignment->isAvailableToStudent($student)) {
                    throw ValidationException::withMessages(['file' => 'الواجب غير متاح لهذا الطالب.'])
                        ->redirectTo(route('student.assignments.show', $assignment, false));
                }
                $deadline = AssignmentDeadline::for($lockedAssignment);
                if ($deadline && now()->gte($deadline)) {
                    throw ValidationException::withMessages(['file' => 'انتهى موعد تسليم الواجب.'])
                        ->redirectTo(route('student.assignments.show', $assignment, false));
                }

                $submission = AssignmentSubmission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();
                if ($submission && ($submission->graded_at || $submission->score !== null || $submission->status === 'graded')) {
                    throw ValidationException::withMessages([
                        'file' => 'لا يمكن استبدال التسليم بعد تصحيحه.',
                    ])->redirectTo(route('student.assignments.show', $assignment, false));
                }
                $submissionValues = [
                    'file_path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => (string) $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'notes' => $notes,
                    'submitted_at' => now(),
                    'status' => 'submitted',
                ];

                if ($submission) {
                    $submission->update($submissionValues);
                } else {
                    $submission = AssignmentSubmission::query()->create($submissionValues + [
                        'assignment_id' => $assignment->id,
                        'student_id' => $student->id,
                    ]);
                }

                $attachment = AssignmentSubmissionAttachment::query()
                    ->where('assignment_submission_id', $submission->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                $oldFile = $attachment ? [
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                ] : null;
                $attachmentValues = [
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ];

                if ($attachment) {
                    $attachment->update($attachmentValues);
                } else {
                    $submission->attachments()->create($attachmentValues);
                }

                return [$submission->load('fileAttachment'), $oldFile];
            });
        } catch (Throwable $exception) {
            $this->deleteFileWithoutAffectingSubmission('local', $path);
            throw $exception;
        }

        if ($oldFile && $oldFile['path'] !== $path && $this->canDeleteSubmissionFile(
            $oldFile['disk'],
            $oldFile['path'],
            $assignment,
            $student,
        )) {
            $this->deleteFileWithoutAffectingSubmission($oldFile['disk'], $oldFile['path']);
        }

        return $submission;
    }

    private function deleteFileWithoutAffectingSubmission(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function canDeleteSubmissionFile(
        ?string $disk,
        string $path,
        Assignment $assignment,
        Student $student,
    ): bool {
        $normalized = str_replace('\\', '/', $path);

        return $disk === 'local'
            && $normalized === $path
            && ! str_contains($normalized, "\0")
            && ! preg_match('#(^|/)\.\.(/|$)#', $normalized)
            && ! str_starts_with($normalized, '/')
            && ! preg_match('/^[a-z]:\//i', $normalized)
            && str_starts_with($normalized, "assignment-submissions/{$assignment->id}/{$student->id}/");
    }
}
