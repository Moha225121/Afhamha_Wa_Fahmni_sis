<?php

namespace App\Http\Controllers\StudentPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentSubmissionRequest;
use App\Http\Requests\SaveExamAnswerRequest;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Student;
use App\Services\AssignmentSubmissionService;
use App\Services\ExamAttemptPolicy;
use App\Services\ExamAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AcademicController extends Controller
{
    public function __construct(
        private readonly AssignmentSubmissionService $submissions,
        private readonly ExamAttemptService $attempts,
        private readonly ExamAttemptPolicy $attemptPolicy,
    ) {}

    public function assignments(Request $request): View
    {
        $student = $this->student($request);
        $assignments = Assignment::query()
            ->with(['subject', 'submissions' => fn ($query) => $query
                ->where('student_id', $student->id)
                ->whereNotNull('submitted_at')])
            ->where('classroom_id', $student->classroom_id)
            ->whereIn('status', ['published', 'active'])
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->latest('due_at')
            ->paginate(20);

        return view('student.assignments.index', compact('student', 'assignments'));
    }

    public function assignment(Request $request, Assignment $assignment): View
    {
        $student = $this->student($request);
        $this->authorizeAssignment($assignment, $student);
        $assignment->load(['subject', 'attachments']);
        $submission = $assignment->submissions()
            ->with('fileAttachment')
            ->where('student_id', $student->id)
            ->whereNotNull('submitted_at')
            ->first();

        return view('student.assignments.show', compact('student', 'assignment', 'submission'));
    }

    public function submitAssignment(AssignmentSubmissionRequest $request, Assignment $assignment): RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeAssignment($assignment, $student);
        abort_if($assignment->due_at && now()->gte($assignment->due_at), 422, 'انتهى موعد تسليم الواجب.');

        $validated = $request->validated();
        $this->submissions->save($assignment, $student, $request->file('file'), $validated['notes'] ?? null);

        return back()->with('success', 'تم حفظ تسليم الواجب بنجاح.');
    }

    public function assignmentAttachment(Request $request, Assignment $assignment): StreamedResponse
    {
        $student = $this->student($request);
        $this->authorizeAssignment($assignment, $student);
        $attachment = $assignment->attachments()->firstOrFail();

        return $this->downloadAssignmentAttachment($attachment);
    }

    public function attachmentFile(Request $request, AssignmentAttachment $attachment): StreamedResponse
    {
        $student = $this->student($request);
        $attachment->load('assignment');
        $this->authorizeAssignment($attachment->assignment, $student);

        return $this->downloadAssignmentAttachment($attachment);
    }

    private function downloadAssignmentAttachment(AssignmentAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->hasValidPrivateMetadata(), 404);
        $disk = $attachment->privateDisk();
        $path = $attachment->path ?: $attachment->file_path;
        $this->authorizePrivateFile(
            $disk,
            $path,
            "assignment-attachments/{$attachment->assignment_id}/",
        );

        $this->authorizeActualFile($disk, $path, $attachment->mime_type);

        return Storage::disk($disk)->download(
            $path,
            $this->safeDownloadName($attachment->original_name),
        );
    }

    public function submissionFile(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $student = $this->student($request);
        abort_unless($submission->student_id === $student->id, 404);
        $submission->loadMissing('fileAttachment');
        $attachment = $submission->fileAttachment;
        abort_unless($attachment?->hasValidPrivateMetadata(), 404);
        $disk = $attachment->privateDisk();
        $this->authorizePrivateFile(
            $disk,
            $attachment->path,
            "assignment-submissions/{$submission->assignment_id}/{$student->id}/",
        );
        $this->authorizeActualFile($disk, $attachment->path, $attachment->mime_type);

        return Storage::disk($disk)->download(
            $attachment->path,
            $this->safeDownloadName($attachment->original_name),
        );
    }

    public function exams(Request $request): View
    {
        $student = $this->student($request);
        $exams = Exam::query()
            ->with(['subject', 'attempts' => fn ($query) => $query->where('student_id', $student->id)->orderByDesc('attempt_number')])
            ->where('classroom_id', $student->classroom_id)
            ->whereIn('status', ['published', 'scheduled'])
            ->orderBy('starts_at')
            ->paginate(30);

        $examGroups = collect(['available' => collect(), 'upcoming' => collect(), 'completed' => collect(), 'past' => collect()]);
        foreach ($exams as $exam) {
            $attempt = $exam->attempts->first();
            $scheduledEnd = $exam->starts_at->copy()->addMinutes($exam->duration_minutes);
            $hasRemainingAttempts = $exam->attempts->count() < $this->attemptPolicy->maximumAttempts($exam);
            $exam->setAttribute('has_remaining_attempts', $hasRemainingAttempts);
            $group = match (true) {
                $exam->starts_at->isFuture() && ! $exam->starts_at->isSameDay(now()) => 'upcoming',
                $attempt && $attempt->status === 'in_progress' && now()->lt($scheduledEnd) => 'available',
                now()->lt($scheduledEnd) && $hasRemainingAttempts => 'available',
                $attempt && $attempt->status !== 'in_progress' => 'completed',
                default => 'past',
            };
            $examGroups[$group]->push($exam);
        }

        return view('student.exams.index', compact('student', 'exams', 'examGroups'));
    }

    public function startExam(Request $request, Exam $exam): RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeExam($exam, $student);
        $examStart = $exam->starts_at;
        $examEnd = $examStart->copy()->addMinutes($exam->duration_minutes);
        $isSameDayExam = $examStart->isSameDay(now());

        abort_if($examStart->isFuture() && ! $isSameDayExam, 422, '�� ���� ���� �������� ���.');
        abort_if(now()->gte($examEnd), 422, '����� ��� ��������.');

        $attempt = $this->attempts->start($exam, $student);

        return redirect()->route($attempt->status === 'in_progress' ? 'student.exams.attempt' : 'student.exams.result', $attempt);
    }

    public function attempt(Request $request, ExamAttempt $attempt): View|RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeAttempt($attempt, $student);

        if ($attempt->status !== 'in_progress') {
            return redirect()->route('student.exams.result', $attempt);
        }

        if (now()->gte($attempt->expires_at)) {
            $this->attempts->finalize($attempt);

            return redirect()->route('student.exams.result', $attempt)
                ->with('success', 'انتهى وقت الاختبار وتم إرسال الإجابات المحفوظة.');
        }

        $attempt->load(['exam', 'answers.question']);

        return view('student.exams.attempt', compact('student', 'attempt'));
    }

    public function saveAnswer(SaveExamAnswerRequest $request, ExamAttempt $attempt, ExamQuestion $question): RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeAttempt($attempt, $student);
        abort_unless($question->exam_id === $attempt->exam_id, 404);

        $saved = $this->attempts->saveAnswer($attempt, $question, $request->validated('answer'));
        if (! $saved) {
            return redirect()->route('student.exams.result', $attempt)->withErrors('انتهت المحاولة ولا يمكن تعديل الإجابات.');
        }

        return back()->with('success', 'تم حفظ الإجابة.');
    }

    public function submitExam(Request $request, ExamAttempt $attempt): RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeAttempt($attempt, $student);
        $attempt = $this->attempts->finalize($attempt);
        $message = $attempt->status === 'pending_review'
            ? 'تم إرسال الاختبار وهو بانتظار مراجعة المعلم.'
            : 'تم إرسال الاختبار وتصحيحه.';

        return redirect()->route('student.exams.result', $attempt)->with('success', $message);
    }

    public function result(Request $request, ExamAttempt $attempt): View|RedirectResponse
    {
        $student = $this->student($request);
        $this->authorizeAttempt($attempt, $student);

        if ($attempt->status === 'in_progress' && now()->gte($attempt->expires_at)) {
            $attempt = $this->attempts->finalize($attempt);
        }
        if ($attempt->status === 'in_progress') {
            return redirect()->route('student.exams.attempt', $attempt);
        }

        $attempt->load(['exam.subject']);

        return view('student.exams.result', compact('student', 'attempt'));
    }

    private function student(Request $request): Student
    {
        return $request->user()->student()->with(['user', 'classroom'])->firstOrFail();
    }

    private function authorizeAssignment(Assignment $assignment, Student $student): void
    {
        abort_unless(
            $assignment->classroom_id === $student->classroom_id
            && in_array($assignment->status, ['published', 'active'], true)
            && (! $assignment->published_at || $assignment->published_at->lte(now())),
            404,
        );
    }

    private function authorizeExam(Exam $exam, Student $student): void
    {
        abort_unless(
            $exam->classroom_id === $student->classroom_id
            && in_array($exam->status, ['published', 'scheduled'], true),
            404,
        );
    }

    private function authorizeAttempt(ExamAttempt $attempt, Student $student): void
    {
        abort_unless($attempt->student_id === $student->id, 404);
    }

    private function authorizePrivateFile(string $disk, string $path, ?string $requiredPrefix = null): void
    {
        $normalized = str_replace('\\', '/', $path);
        $hasTraversal = preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1;
        $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[a-z]:\//i', $normalized) === 1;

        abort_unless(
            $disk === config('student_academic.private_files.disk', 'local')
            && $normalized !== ''
            && $normalized === $path
            && ! $hasTraversal
            && ! $isAbsolute
            && ! str_contains($normalized, "\0")
            && ($requiredPrefix === null || str_starts_with($normalized, $requiredPrefix))
            && Storage::disk($disk)->exists($normalized),
            404,
        );

        if ($requiredPrefix !== null) {
            $this->authorizeResolvedPath($disk, $normalized, $requiredPrefix);
        }
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
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?: 'download';

        return Str::limit($name, 255, '');
    }
}
