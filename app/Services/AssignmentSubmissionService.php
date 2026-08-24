<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AssignmentSubmissionService
{
    public function save(Assignment $assignment, Student $student, UploadedFile $file, ?string $notes): AssignmentSubmission
    {
        $path = $file->store("assignment-submissions/{$assignment->id}/{$student->id}", 'local');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('تعذر حفظ ملف التسليم.');
        }

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $originalName = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $originalName) ?: 'submission';
        $originalName = Str::limit($originalName, 255, '');

        try {
            [$submission, $oldPath] = DB::transaction(function () use ($assignment, $student, $path, $originalName, $file, $notes): array {
                $lockedAssignment = Assignment::query()->lockForUpdate()->findOrFail($assignment->id);
                if ($lockedAssignment->classroom_id !== $student->classroom_id
                    || $lockedAssignment->status !== 'published'
                    || ($lockedAssignment->published_at && $lockedAssignment->published_at->gt(now()))) {
                    throw ValidationException::withMessages(['file' => 'الواجب غير متاح لهذا الطالب.']);
                }
                if (now()->gte($lockedAssignment->due_at)) {
                    throw ValidationException::withMessages(['file' => 'انتهى موعد تسليم الواجب.']);
                }

                $submission = AssignmentSubmission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('student_id', $student->id)
                    ->lockForUpdate()
                    ->first();
                $oldPath = $submission?->file_path;
                $values = [
                    'file_path' => $path,
                    'original_name' => $originalName,
                    'mime_type' => (string) $file->getMimeType(),
                    'file_size' => (int) $file->getSize(),
                    'notes' => $notes,
                    'submitted_at' => now(),
                    'status' => 'submitted',
                ];

                if ($submission) {
                    $submission->update($values);
                } else {
                    $submission = AssignmentSubmission::query()->create($values + ['assignment_id' => $assignment->id, 'student_id' => $student->id]);
                }

                return [$submission, $oldPath];
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return $submission;
    }
}
