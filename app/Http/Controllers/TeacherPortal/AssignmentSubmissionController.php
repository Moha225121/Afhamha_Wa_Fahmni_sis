<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentSubmissionController extends Controller
{
    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $assignments = $this->assignmentsFor($teacher)
            ->with(['subject', 'classroom'])
            ->withCount('submissions')
            ->latest('due_at')
            ->paginate(20);

        return view('teacher.assignments.index', compact('teacher', 'assignments'));
    }

    public function show(Request $request, Assignment $assignment): View
    {
        $teacher = $this->teacher($request);
        $assignment = $this->assignmentsFor($teacher)->whereKey($assignment->id)->firstOrFail();
        $assignment->load(['subject', 'classroom', 'submissions.student.user']);

        return view('teacher.assignments.show', compact('teacher', 'assignment'));
    }

    public function download(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $teacher = $this->teacher($request);
        $submission->load('assignment');
        $this->assignmentsFor($teacher)->whereKey($submission->assignment_id)->firstOrFail();
        $this->authorizePrivateFile($submission->file_path);

        return Storage::disk('local')->download($submission->file_path, $this->safeDownloadName($submission->original_name));
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

    private function authorizePrivateFile(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        $hasTraversal = preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1;
        $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[a-z]:\//i', $normalized) === 1;

        abort_unless(
            $normalized !== ''
            && $normalized === $path
            && ! $hasTraversal
            && ! $isAbsolute
            && ! str_contains($normalized, "\0")
            && Storage::disk('local')->exists($normalized),
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
