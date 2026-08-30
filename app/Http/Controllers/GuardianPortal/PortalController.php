<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\ParentPortalContext;
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
            'announcements' => $this->announcementsFor($children, 4),
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

        return view('parent.results', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $this->summaryFor($selectedStudent),
            'recentGrades' => $this->recentGradesFor($selectedStudent, 30),
        ]);
    }

    public function attendance(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);

        $range = match ($request->query('period')) { 'week' => now()->startOfWeek(), 'semester' => now()->subMonths(4), 'custom' => $request->date('from'), default => now()->startOfMonth() };
        return view('parent.attendance', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $this->summaryFor($selectedStudent),
            'records' => $selectedStudent
                ? DB::table('attendance_records')->where('student_id', $selectedStudent->id)->when($range, fn ($q) => $q->whereDate('date', '>=', $range))->when($request->date('to'), fn ($q,$v) => $q->whereDate('date','<=',$v))->latest('date')->get()
                : collect(),
        ]);
    }

    public function guardianCalls(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        return view('parent.guardian-calls', ['children'=>$children,'selectedStudent'=>$selectedStudent,'calls'=>$selectedStudent ? \App\Models\GuardianCall::where('student_id',$selectedStudent->id)->latest()->get() : collect()]);
    }

    public function studentFollowup(Request $request): View
    {
        [, $children, $selectedStudent] = $this->parentContext($request);
        return view('parent.student-followup', ['children'=>$children,'selectedStudent'=>$selectedStudent,'notes'=>$selectedStudent ? \App\Models\StudentNote::where('student_id',$selectedStudent->id)->where('visibility','guardian')->latest()->get() : collect()]);
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

        return view('parent.assignments', compact('children', 'selectedStudent', 'assignments'));
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
                    'exams.id', 'exams.title', 'exams.starts_at', 'exams.duration_minutes', 'exams.total_score',
                    'subjects.name as subject', 'grades.score', 'grades.published_at as grade_published_at',
                    'automatic_attempts.status as automatic_status',
                    'automatic_attempts.score as automatic_score',
                    'automatic_attempts.maximum_score as automatic_maximum_score',
                    'automatic_attempts.percentage as automatic_percentage',
                ])
                ->orderBy('exams.starts_at')
                ->get();
        }

        return view('parent.exams', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'upcomingExams' => $exams->filter(fn ($exam) => Carbon::parse($exam->starts_at)->greaterThanOrEqualTo(now())),
            'previousExams' => $exams->filter(fn ($exam) => Carbon::parse($exam->starts_at)->lessThan(now())),
        ]);
    }

    public function messages(Request $request): View
    {
        [$guardian, $children, $selectedStudent] = $this->parentContext($request);

        return view('parent.messages', [
            'guardian' => $guardian,
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'announcements' => $this->announcementsFor($children, 12),
        ]);
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

    private function recentGradesFor(?Student $student, int $limit): Collection
    {
        return $this->publishedResultsFor($student)->take($limit)->values();
    }

    private function publishedResultsFor(?Student $student): Collection
    {
        if (! $student) {
            return collect();
        }

        $manual = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('grades.student_id', $student->id)
            ->where('exams.status', 'published')
            ->whereNotNull('grades.published_at')
            ->select([
                'exams.id as exam_id', 'grades.score', 'grades.published_at', 'exams.title',
                'exams.total_score', 'subjects.name as subject',
            ])
            ->get();

        $automatic = DB::table('exam_attempts')
            ->join('exams', 'exam_attempts.exam_id', '=', 'exams.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('exam_attempts.student_id', $student->id)
            ->where('exam_attempts.status', 'submitted')
            ->whereNotNull('exam_attempts.percentage')
            ->where('exams.status', 'published')
            ->when(
                $manual->isNotEmpty(),
                fn ($query) => $query->whereNotIn('exam_attempts.exam_id', $manual->pluck('exam_id')),
            )
            ->select([
                'exams.id as exam_id', 'exam_attempts.score', 'exam_attempts.submitted_at as published_at',
                'exams.title', 'exam_attempts.maximum_score as total_score', 'subjects.name as subject',
            ])
            ->orderByDesc('exam_attempts.id')
            ->get()
            ->unique('exam_id');

        return $manual
            ->concat($automatic)
            ->sortByDesc(fn ($result) => $result->published_at ? Carbon::parse($result->published_at)->getTimestamp() : 0)
            ->values();
    }

    /** @param Collection<int, Student> $children */
    private function announcementsFor(Collection $children, int $limit): Collection
    {
        $classroomIds = $children->pluck('classroom_id')->filter()->unique()->values();

        return Announcement::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($classroomIds): void {
                $query->whereIn('audience', ['all', 'parent', 'parents']);

                if ($classroomIds->isNotEmpty()) {
                    $query->orWhereIn('classroom_id', $classroomIds);
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
