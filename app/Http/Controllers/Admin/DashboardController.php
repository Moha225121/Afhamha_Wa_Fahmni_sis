<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = now()->toDateString();
        $attendance = DB::table('attendance_records')->whereDate('date', $today);
        $total = (clone $attendance)->count();
        $present = (clone $attendance)->where('status', 'present')->count();
        $attendanceByYear = DB::table('academic_years')
            ->leftJoin('classrooms', 'classrooms.academic_year_id', '=', 'academic_years.id')
            ->leftJoin('attendance_records', 'attendance_records.classroom_id', '=', 'classrooms.id')
            ->select('academic_years.name')
            ->selectRaw('count(attendance_records.id) as total')
            ->selectRaw("sum(case when attendance_records.status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when attendance_records.status = 'absent' then 1 else 0 end) as absent")
            ->selectRaw("sum(case when attendance_records.status = 'late' then 1 else 0 end) as late")
            ->groupBy('academic_years.id', 'academic_years.name')
            ->orderByDesc('academic_years.name')
            ->get()
            ->map(function ($row) {
                $row->rate = $row->total ? round($row->present / $row->total * 100) : 0;

                return $row;
            });

        $weeklyAttendance = collect(range(6, 0))->map(function (int $daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();
            $total = DB::table('attendance_records')->whereDate('date', $date)->count();
            $present = DB::table('attendance_records')->whereDate('date', $date)->where('status', 'present')->count();

            return (object) [
                'date' => $date,
                'label' => now()->subDays($daysAgo)->translatedFormat('D'),
                'rate' => $total ? round($present * 100 / $total) : 0,
            ];
        });
        $stageDistribution = DB::table('classrooms')
            ->join('students', 'students.classroom_id', '=', 'classrooms.id')
            ->where('students.status', 'active')
            ->select('classrooms.stage')
            ->selectRaw('count(students.id) as total')
            ->groupBy('classrooms.stage')
            ->orderByDesc('total')
            ->get();
        $totalActiveStudents = max(1, Student::where('status', 'active')->count());
        $stageDistribution->each(fn ($stage) => $stage->rate = round($stage->total * 100 / $totalActiveStudents));
        $academicPerformance = DB::table('grades')
            ->selectRaw("case when score >= 90 then 'ممتاز' when score >= 75 then 'جيد جدًا' when score >= 60 then 'جيد' else 'يحتاج متابعة' end as label")
            ->selectRaw('count(*) as total')
            ->groupBy('label')
            ->orderByDesc('total')
            ->get();
        $activities = AuditLog::latest('created_at')->limit(8)->get();
        $gradesTotal = DB::table('grades')->count();

        $stats = ['students' => Student::where('status', 'active')->count(), 'teachers' => Teacher::where('status', 'active')->count(), 'parents' => Guardian::where('status', 'active')->count(), 'classes' => Classroom::count(), 'attendance_rate' => $total ? round($present * 100 / $total, 1) : 0, 'absent' => (clone $attendance)->where('status', 'absent')->count(), 'upcoming_exams' => DB::table('exams')->where('starts_at', '>=', now())->whereIn('status', ['scheduled', 'published'])->count(), 'announcements' => Announcement::where('status', 'published')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count()];

        return view('admin.dashboard', compact('stats', 'attendanceByYear', 'activities', 'weeklyAttendance', 'stageDistribution', 'academicPerformance', 'gradesTotal'));
    }
}
