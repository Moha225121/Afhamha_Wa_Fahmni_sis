<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\ParentPortalContext;
use App\Services\SubjectDisplayName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(private readonly ParentPortalContext $context) {}

    public function dashboard(Request $request): View
    {
        [$guardian, $children, $selectedStudent] = $this->parentContext($request);

        return view('parent.dashboard', [
            'guardian' => $guardian,
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $this->summaryFor($selectedStudent),
            'recentGrades' => $this->recentGradesFor($selectedStudent, 3),
            'announcements' => $this->announcementsFor($guardian, 4),
        ]);
    }

    public function children(Request $request): View
    {
        $guardian = $this->context->guardian($request);

        return view('parent.children.index', [
            'guardian' => $guardian,
            'children' => $this->context->children($guardian),
        ]);
    }

    public function child(Request $request, Student $student): View
    {
        $guardian = $this->context->guardian($request);
        $this->context->assertChild($guardian, $student);
        $student->load(['user', 'classroom.academicYear']);

        return view('parent.children.show', [
            'guardian' => $guardian,
            'student' => $student,
            'summary' => $this->summaryFor($student),
            'recentGrades' => $this->recentGradesFor($student, 5),
            'latestAttendance' => DB::table('attendance_records')->where('student_id', $student->id)->latest('date')->first(),
        ]);
    }

    public function results(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        $grades = $this->publishedResultsFor($selectedStudent)->each(function ($grade): void {
            $grade->subject_label = SubjectDisplayName::arabic($grade->subject);
        });
        [$selectedSubjectId, $subjects] = $this->subjectFilter($request, $grades);
        $grades = $grades->when($selectedSubjectId, fn ($items) => $items->where('subject_id', $selectedSubjectId))->values();
        $percentages = $grades->filter(fn ($grade) => (float) $grade->total_score > 0)
            ->map(fn ($grade) => (float) $grade->score * 100 / (float) $grade->total_score);
        $publications = $selectedStudent ? app(\App\Services\ResultPublicationService::class)->publications($selectedStudent) : collect();
        $publications = $publications->map(function ($publication) use ($selectedSubjectId) {
            $publication->result['subjects'] = collect($publication->result['subjects'])
                ->when($selectedSubjectId, fn ($items) => $items->where('subject_id', $selectedSubjectId))
                ->map(fn ($subject) => $subject + ['subject_label' => SubjectDisplayName::arabic($subject['subject'])])->values()->all();

            return $publication;
        })->filter(fn ($publication) => count($publication->result['subjects']) > 0)->values();

        return view('parent.results', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'subjects' => $subjects,
            'selectedSubjectId' => $selectedSubjectId,
            'summary' => [
                'published_grades' => $grades->count(),
                'average_percent' => $percentages->isEmpty() ? null : round((float) $percentages->avg(), 1),
            ],
            // Period subjects appear in their report once; individual exams appear below.
            'recentGrades' => $grades->filter(fn ($grade) => isset($grade->exam_id))->values(),
            'publications' => $publications,
        ]);
    }

    public function attendance(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        $filters = $request->validate([
            'period' => ['nullable', \Illuminate\Validation\Rule::in(['all', 'week', 'month', 'semester', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $period = $filters['period'] ?? 'all';
        [$dateFrom, $dateTo] = match ($period) {
            'week' => [now()->startOfWeek(Carbon::SUNDAY)->toDateString(), now()->endOfWeek(Carbon::SATURDAY)->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'semester' => [now()->subMonths(4)->toDateString(), today()->toDateString()],
            'custom' => [$filters['from'] ?? null, $filters['to'] ?? null],
            default => [null, null],
        };
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $query = DB::table('attendance_records')->where('student_id', $selectedStudent?->id ?? 0)
            ->when($dateFrom, fn ($items) => $items->whereDate('date', '>=', $dateFrom))
            ->when($dateTo, fn ($items) => $items->whereDate('date', '<=', $dateTo));
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        $present = (int) ($counts['present'] ?? 0);
        $late = (int) ($counts['late'] ?? 0) + (int) ($counts['excused_late'] ?? 0);
        $summary = [
            'attendance_total' => $total, 'present' => $present,
            'absent' => (int) ($counts['absent'] ?? 0) + (int) ($counts['excused_absence'] ?? 0), 'late' => $late,
            'excused' => (int) ($counts['excused_absence'] ?? 0) + (int) ($counts['excused_late'] ?? 0),
            'attendance_percent' => $total ? round(($present + $late) / $total * 100, 1) : null,
        ];
        return view('parent.attendance', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $summary, 'period' => $period, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
            'records' => $query->orderByDesc('date')->orderByDesc('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function assignments(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        $assignments = collect();

        if ($selectedStudent?->classroom_id) {
            $assignments = Assignment::query()
                ->with([
                    'subject',
                    'attachments',
                    'submissions' => fn ($query) => $query->where('student_id', $selectedStudent->id),
                ])
                ->where('classroom_id', $selectedStudent->classroom_id)
                ->whereIn('status', ['published', 'active'])
                ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->orderByRaw('case when due_at is null then 1 else 0 end')
                ->orderBy('due_at')
                ->get();
        }

        $assignments->each(function ($assignment): void {
            $assignment->subject_label = SubjectDisplayName::arabic($assignment->subject?->name);
        });
        [$selectedSubjectId, $subjects] = $this->subjectFilter($request, $assignments);
        $assignments = $assignments->when($selectedSubjectId, fn ($items) => $items->where('subject_id', $selectedSubjectId))->values();

        return view('parent.assignments', compact('children', 'selectedStudent', 'assignments', 'subjects', 'selectedSubjectId'));
    }

    public function exams(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        $exams = collect();

        if ($selectedStudent?->classroom_id) {
            $latestAttemptIds = DB::table('exam_attempts')
                ->selectRaw('exam_id, max(id) as attempt_id')
                ->where('student_id', $selectedStudent->id)
                ->whereIn('status', ['submitted', 'pending_review'])
                ->groupBy('exam_id');
            $exams = DB::table('exams')
                ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
                ->leftJoin('grades', function ($join) use ($selectedStudent): void {
                    $join->on('grades.exam_id', '=', 'exams.id')->where('grades.student_id', '=', $selectedStudent->id);
                })
                ->leftJoinSub($latestAttemptIds, 'latest_attempts', function ($join): void {
                    $join->on('latest_attempts.exam_id', '=', 'exams.id');
                })
                ->leftJoin('exam_attempts as automatic_attempts', 'automatic_attempts.id', '=', 'latest_attempts.attempt_id')
                ->where('exams.classroom_id', $selectedStudent->classroom_id)
                ->whereIn('exams.status', ['published', 'scheduled'])
                ->select([
                    'exams.id', 'exams.subject_id', 'exams.status', 'exams.exam_period_id', 'exams.legacy_results_published', 'exams.title', 'exams.starts_at', 'exams.duration_minutes', 'exams.total_score',
                    'subjects.name as subject', 'grades.score', 'grades.published_at as grade_published_at',
                    'automatic_attempts.status as automatic_status',
                    'automatic_attempts.score as automatic_score',
                    'automatic_attempts.maximum_score as automatic_maximum_score',
                    'automatic_attempts.percentage as automatic_percentage',
                ])
                ->orderBy('exams.starts_at')
                ->get()->map(function ($exam) {
                    if (!app(\App\Services\ResultPublicationService::class)->legacyAttemptVisible($exam)) {
                        $exam->score = null; $exam->automatic_score = null; $exam->automatic_percentage = null; $exam->grade_published_at = null;
                    }
                    return $exam;
                });
        }

        $exams->each(function ($exam): void {
            $exam->subject_label = SubjectDisplayName::arabic($exam->subject);
        });
        [$selectedSubjectId, $subjects] = $this->subjectFilter($request, $exams);
        $exams = $exams->when($selectedSubjectId, fn ($items) => $items->where('subject_id', $selectedSubjectId))->values();

        return view('parent.exams', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'subjects' => $subjects,
            'selectedSubjectId' => $selectedSubjectId,
            'upcomingExams' => $exams->filter(fn ($exam) => Carbon::parse($exam->starts_at)->greaterThanOrEqualTo(now())),
            'previousExams' => $exams->filter(fn ($exam) => Carbon::parse($exam->starts_at)->lessThan(now())),
        ]);
    }

    public function messages(Request $request): View
    {
        return app(ConversationController::class)->index($request);
    }

    public function profile(Request $request): View
    {
        return view('parent.profile', ['guardian' => $this->context->guardian($request)]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($validated);

        return redirect()->route('parent.dashboard')->with('success', 'تم حفظ الملف الشخصي.');
    }

    public function more(Request $request): RedirectResponse
    {
        return redirect()->route('parent.dashboard');
    }

    /** @return array{0: Guardian, 1: Collection<int, Student>, 2: Student|null} */
    private function parentContext(Request $request): array
    {
        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);

        return [$guardian, $children, $this->context->selectedStudent($guardian, $children, $request->query('student'))];
    }

    private function summaryFor(?Student $student): array
    {
        if (! $student) {
            return [
                'attendance_total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'attendance_percent' => null,
                'published_grades' => 0,
                'average_percent' => null,
            ];
        }

        $attendance = DB::table('attendance_records')
            ->where('student_id', $student->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->selectRaw("sum(case when status = 'late' then 1 else 0 end) as late")
            ->selectRaw("sum(case when status = 'excused_absence' then 1 else 0 end) as excused_absence")
            ->selectRaw("sum(case when status = 'excused_late' then 1 else 0 end) as excused_late")
            ->first();

        $results = $this->publishedResultsFor($student);
        $percentages = $results
            ->filter(fn ($result) => (float) $result->total_score > 0)
            ->map(fn ($result) => (float) $result->score * 100 / (float) $result->total_score);

        $total = (int) ($attendance->total ?? 0);
        $present = (int) ($attendance->present ?? 0);

        return [
            'attendance_total' => $total,
            'present' => $present,
            'absent' => (int) ($attendance->absent ?? 0),
            'late' => (int) ($attendance->late ?? 0),
            'excused' => (int) (($attendance->excused_absence ?? 0) + ($attendance->excused_late ?? 0)),
            'excused_absence' => (int) ($attendance->excused_absence ?? 0),
            'excused_late' => (int) ($attendance->excused_late ?? 0),
            'attendance_percent' => $total ? round(($present / $total) * 100, 1) : null,
            'published_grades' => $results->count(),
            'average_percent' => $percentages->isEmpty() ? null : round((float) $percentages->avg(), 1),
        ];
    }

    /** Subject choices come only from this child's visible records on the current page. */
    private function subjectFilter(Request $request, Collection $items): array
    {
        $validated = $request->validate(['subject' => ['nullable', 'integer', 'min:1']]);
        $selectedSubjectId = isset($validated['subject']) ? (int) $validated['subject'] : null;
        $subjects = $items->filter(fn ($item) => $item->subject_id)
            ->mapWithKeys(fn ($item) => [$item->subject_id => $item->subject_label])->sort(SORT_STRING);

        return [$selectedSubjectId, $subjects];
    }

    private function recentGradesFor(?Student $student, int $limit): Collection
    {
        return $this->publishedResultsFor($student)->take($limit)->values();
    }

    private function publishedResultsFor(?Student $student): Collection
    {
        return app(\App\Services\ResultPublicationService::class)->visibleResults($student);
    }

    private function announcementsFor(Guardian $guardian, int $limit): Collection
    {
        return Announcement::visibleToParent($guardian)
            ->orderByRaw('COALESCE(published_at, created_at) DESC')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
