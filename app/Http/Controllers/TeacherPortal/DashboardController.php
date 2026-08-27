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

        $stats = [
            'classrooms' => $classroomIds->count(),
            'students' => $studentsCount,
            'today_attendance' => $todayAttendance,
            'draft_exams' => DB::table('exams')->where('teacher_id', $teacher->id)->where('status', 'draft')->count(),
            'active_assignments' => DB::table('assignments')->where('teacher_id', $teacher->id)->where('status', 'active')->count(),
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

        return view('teacher.dashboard', compact('teacher', 'stats', 'upcomingExams', 'upcomingAssignments'));
    }
}
