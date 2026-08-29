<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);

        $studentsCount = DB::table('students')->whereIn('classroom_id', $classroomIds)->where('status', 'active')->count();

        $today = now()->toDateString();
        $todayAttendance = DB::table('attendance_records')->whereIn('classroom_id', $classroomIds)->whereDate('date', $today)->count();
        $attendanceRate = DB::table('attendance_records')
            ->whereIn('classroom_id', $classroomIds)
            ->whereDate('date', $today)
            ->selectRaw("round(sum(case when status = 'present' then 1 else 0 end) * 100.0 / nullif(count(*), 0), 0) as rate")
            ->value('rate');

        $stats = [
            'classrooms' => $classroomIds->count(),
            'students' => $studentsCount,
            'today_attendance' => $todayAttendance,
            'attendance_rate' => $attendanceRate ?? 0,
            'draft_exams' => DB::table('exams')->where('teacher_id', $teacher->id)->where('status', 'draft')->count(),
            'active_assignments' => DB::table('assignments')->where('teacher_id', $teacher->id)->whereIn('status', ['active', 'published'])->count(),
            'lessons' => DB::table('lessons')
                ->where('teacher_id', $teacher->id)
                ->where(function ($query) {
                    $query->where('status', 'draft')
                        ->orWhere(function ($query) {
                            $query->where('status', 'published')
                                ->where('published_at', '>', now());
                        });
                })
                ->count(),
        ];

        $upcomingExams = DB::table('exams')
            ->join('classrooms', 'exams.classroom_id', '=', 'classrooms.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('exams.teacher_id', $teacher->id)
            ->where('exams.starts_at', '>=', now())
            ->select('exams.*', 'classrooms.name as classroom', 'subjects.name as subject')
            ->orderBy('exams.starts_at')
            ->limit(5)
            ->get();

        $upcomingAssignments = DB::table('assignments')
            ->join('classrooms', 'assignments.classroom_id', '=', 'classrooms.id')
            ->join('subjects', 'assignments.subject_id', '=', 'subjects.id')
            ->where('assignments.teacher_id', $teacher->id)
            ->where('assignments.due_date', '>=', $today)
            ->select('assignments.*', 'classrooms.name as classroom', 'subjects.name as subject')
            ->orderBy('assignments.due_date')
            ->limit(5)
            ->get();

        $todayDetails = DB::table('attendance_records')
            ->join('students', 'attendance_records.student_id', '=', 'students.id')
            ->join('users', 'students.user_id', '=', 'users.id')
            ->join('classrooms', 'attendance_records.classroom_id', '=', 'classrooms.id')
            ->whereIn('attendance_records.classroom_id', $classroomIds)
            ->whereDate('attendance_records.date', $today)
            ->select('users.name as student', 'classrooms.name as classroom', 'attendance_records.status')
            ->orderBy('classrooms.name')
            ->orderBy('users.name')
            ->limit(6)
            ->get();

        return view('teacher.dashboard', compact('teacher', 'stats', 'upcomingExams', 'upcomingAssignments', 'todayDetails'));
    }
}
