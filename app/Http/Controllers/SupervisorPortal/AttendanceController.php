<?php
namespace App\Http\Controllers\SupervisorPortal;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\StoreDailyAttendanceRequest;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\DailyAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
class AttendanceController extends Controller
{
    public function index(Request $r): View
    {
        $classes = $r->user()->supervisedClassrooms()->orderBy('name')->get();
        $classId = (int) ($r->query('classroom_id') ?: $classes->first()?->id);
        abort_if($classId && ! $classes->contains('id', $classId), 403);
        $date = $r->date('date', today())?->toDateString() ?: today()->toDateString();
        $students = Student::with('user')->where('classroom_id', $classId)->where('status', 'active')->get();
        $records = Attendance::whereDate('date', $date)->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        return view('supervisor.attendance.index', compact('classes', 'classId', 'date', 'students', 'records'));
    }

    public function store(StoreDailyAttendanceRequest $r, DailyAttendanceService $service): RedirectResponse
    {
        $d = $r->validated();
        $service->save($r->user(), (int) $d['classroom_id'], $d['date'], $d['records']);

        return back()->with('success', 'تم حفظ حضور اليوم بنجاح.');
    }

    public function history(Request $r): View
    {
        $classes = $r->user()->supervisedClassrooms()->with('academicYear')->orderBy('name')->get();
        $classroomIds = $classes->pluck('id');
        $period = in_array($r->query('period', 'daily'), ['daily', 'weekly', 'monthly', 'custom'], true)
            ? $r->query('period', 'daily')
            : 'daily';
        $anchor = $this->safeDate($r->query('date')) ?? today();
        $month = $this->safeMonth($r->query('month')) ?? $anchor->format('Y-m');

        [$dateFrom, $dateTo] = match ($period) {
            'weekly' => [$anchor->copy()->startOfWeek(Carbon::SUNDAY), $anchor->copy()->endOfWeek(Carbon::SATURDAY)],
            'monthly' => [Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay(), Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->endOfDay()],
            'custom' => $this->customRange($r, $anchor),
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
        };

        $selectedClassroomId = (int) $r->query('classroom_id') ?: null;
        abort_if($selectedClassroomId && ! $classroomIds->contains($selectedClassroomId), 403);
        $availableStudents = Student::with(['user', 'classroom'])
            ->whereIn('classroom_id', $classroomIds)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (Student $student) => mb_strtolower($student->user?->name ?? '', 'UTF-8'), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $selectedStudentId = (int) $r->query('student_id') ?: null;
        abort_if($selectedStudentId && ! $availableStudents->contains('id', $selectedStudentId), 403);
        $students = $availableStudents
            ->when($selectedClassroomId, fn ($items) => $items->where('classroom_id', $selectedClassroomId))
            ->when($selectedStudentId, fn ($items) => $items->where('id', $selectedStudentId))
            ->values();
        $records = Attendance::with(['student.user', 'student.classroom'])
            ->whereIn('classroom_id', $selectedClassroomId ? [$selectedClassroomId] : $classroomIds)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereBetween('date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderByDesc('date')
            ->orderBy('student_id')
            ->get();

        $isAbsent = static fn (Attendance $record): bool => in_array($record->status->value, ['absent', 'excused_absence'], true);
        $presentCount = $records->reject($isAbsent)->count();
        $absentCount = $records->filter($isAbsent)->count();
        $totalCount = $records->count();
        $summary = [
            'total' => $totalCount,
            'present' => $presentCount,
            'absent' => $absentCount,
            'rate' => $totalCount ? round($presentCount / $totalCount * 100, 1) : 0,
        ];
        $studentSummaries = $students->map(function (Student $student) use ($records, $isAbsent): array {
            $studentRecords = $records->where('student_id', $student->id);
            $absent = $studentRecords->filter($isAbsent)->count();
            $total = $studentRecords->count();

            return [
                'student' => $student,
                'total' => $total,
                'present' => $total - $absent,
                'absent' => $absent,
                'rate' => $total ? round(($total - $absent) / $total * 100, 1) : 0,
            ];
        });
        $dailySummaries = $records->groupBy(fn (Attendance $record) => $record->date->format('Y-m-d'))
            ->map(function ($dayRecords, string $day): array {
                $absent = $dayRecords->filter(fn (Attendance $record) => in_array($record->status->value, ['absent', 'excused_absence'], true))->count();
                $total = $dayRecords->count();

                return ['date' => $day, 'total' => $total, 'present' => $total - $absent, 'absent' => $absent];
            })->values();

        return view('supervisor.attendance.history', compact(
            'classes', 'availableStudents', 'students', 'records', 'studentSummaries', 'dailySummaries',
            'summary', 'period', 'dateFrom', 'dateTo', 'month', 'selectedClassroomId', 'selectedStudentId'
        ));
    }

    private function safeDate(?string $value): ?Carbon
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        try { return Carbon::createFromFormat('Y-m-d', $value)->startOfDay(); } catch (\Throwable) { return null; }
    }

    private function customRange(Request $request, Carbon $fallback): array
    {
        $from = $this->safeDate($request->query('date_from')) ?? $fallback->copy();
        $to = $this->safeDate($request->query('date_to')) ?? $from->copy();
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function safeMonth(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}$/', $value)) return null;
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value.'-01');
            return $date->format('Y-m') === $value ? $value : null;
        } catch (\Throwable) { return null; }
    }
}
