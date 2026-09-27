<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssignmentSubmissionController extends Controller
{
    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $assignments = $this->assignmentsFor($teacher)
            ->with(['subject', 'classroom'])
            ->withCount(['submissions' => fn ($query) => $query->whereNotNull('submitted_at')])
            ->latest('due_at')
            ->paginate(20);

        return view('teacher.assignments.index', compact('teacher', 'assignments'));
    }

    public function show(Request $request, Assignment $assignment): View
    {
        $teacher = $this->teacher($request);
        $assignment = $this->assignmentsFor($teacher)->whereKey($assignment->id)->firstOrFail();
        $assignment->load([
            'subject',
            'classroom',
            'submissions' => fn ($query) => $query
                ->whereNotNull('submitted_at')
                ->with(['student.user', 'fileAttachment']),
        ]);

        return view('teacher.assignments.show', compact('teacher', 'assignment'));
    }

    public function download(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $teacher = $this->teacher($request);
        $submission->load(['assignment', 'fileAttachment']);
        $this->assignmentsFor($teacher)->whereKey($submission->assignment_id)->firstOrFail();
        $attachment = $submission->fileAttachment;
        abort_unless($attachment?->hasValidPrivateMetadata(), 404);
        $this->authorizePrivateFile($submission, $attachment);
        $disk = $attachment->privateDisk();
        $this->authorizeActualFile($disk, $attachment->path, $attachment->mime_type);

        return Storage::disk($disk)->download(
            $attachment->path,
            $this->safeDownloadName($attachment->original_name),
        );
    }

    private function teacher(Request $request): Teacher
    {
        return $request->user()->teacher()->with('user')->firstOrFail();
    }

    private function assignmentsFor(Teacher $teacher): Builder
    {
        return Assignment::query()->where(function (Builder $query) use ($teacher): void {
            $query->where('teacher_id', $teacher->id)
                ->orWhereExists(function ($subquery) use ($teacher): void {
                    $subquery->selectRaw('1')
                        ->from('teacher_assignments')
                        ->whereColumn('teacher_assignments.classroom_id', 'assignments.classroom_id')
                        ->whereColumn('teacher_assignments.subject_id', 'assignments.subject_id')
                        ->where('teacher_assignments.teacher_id', $teacher->id);
                });
        });
    }

    private function authorizePrivateFile(
        AssignmentSubmission $submission,
        AssignmentSubmissionAttachment $attachment,
    ): void {
        $disk = $attachment->privateDisk();
        $normalized = str_replace('\\', '/', $attachment->path);
        $hasTraversal = preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1;
        $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[a-z]:\//i', $normalized) === 1;
        $expectedPrefix = "assignment-submissions/{$submission->assignment_id}/{$submission->student_id}/";

        abort_unless(
            $disk === config('student_academic.private_files.disk', 'local')
            && $normalized !== ''
            && $normalized === $attachment->path
            && ! $hasTraversal
            && ! $isAbsolute
            && ! str_contains($normalized, "\0")
            && str_starts_with($normalized, $expectedPrefix)
            && Storage::disk($disk)->exists($normalized),
            404,
        );

        $this->authorizeResolvedPath($disk, $normalized, $expectedPrefix);
    }

    private function authorizeResolvedPath(string $disk, string $path, string $requiredPrefix): void
    {
        try {
            $diskRoot = realpath(Storage::disk($disk)->path(''));
            $prefixRoot = realpath(Storage::disk($disk)->path(rtrim($requiredPrefix, '/')));
            $filePath = realpath(Storage::disk($disk)->path($path));
        } catch (Throwable) {
            abort(404);
        }

        abort_unless(
            is_string($diskRoot)
            && is_string($prefixRoot)
            && is_string($filePath)
            && $this->resolvedPathIsWithin($prefixRoot, $diskRoot)
            && $this->resolvedPathIsWithin($filePath, $prefixRoot),
            404,
        );
    }

    private function resolvedPathIsWithin(string $path, string $directory): bool
    {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/').'/';

        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $directory = strtolower($directory);
        }

        return str_starts_with($path, $directory);
    }

    private function authorizeActualFile(string $disk, string $path, ?string $recordedMimeType): void
    {
        try {
            $actualSize = Storage::disk($disk)->size($path);
        } catch (Throwable) {
            abort(404);
        }

        abort_unless(
            $actualSize > 0
            && $actualSize <= (int) config('student_academic.private_files.max_bytes', 10 * 1024 * 1024),
            404,
        );

        if (trim((string) $recordedMimeType) !== '') {
            return;
        }

        try {
            $actualMimeType = File::mimeType(Storage::disk($disk)->path($path));
        } catch (Throwable) {
            abort(404);
        }

        abort_unless(
            is_string($actualMimeType)
            && in_array(strtolower($actualMimeType), config('student_academic.private_files.allowed_mime_types', []), true),
            404,
        );
    }

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?: 'submission';

        return Str::limit($name, 255, '');
    }
}
