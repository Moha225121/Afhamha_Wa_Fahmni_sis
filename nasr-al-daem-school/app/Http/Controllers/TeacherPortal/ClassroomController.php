<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClassroomController extends Controller
{
    use Concerns\InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $classrooms = Classroom::query()
            ->whereIn('id', $this->assignedClassroomIds($teacher))
            ->select('classrooms.*')
            ->withCount('students')
            ->selectSub(fn ($query) => $query->from('lessons')->selectRaw('count(*)')->whereColumn('lessons.classroom_id', 'classrooms.id')->where('lessons.teacher_id', $teacher->id), 'lessons_count')
            ->selectSub(fn ($query) => $query->from('assignments')->selectRaw('count(*)')->whereColumn('assignments.classroom_id', 'classrooms.id')->where('assignments.teacher_id', $teacher->id), 'assignments_count')
            ->selectSub(fn ($query) => $query->from('teacher_assignments')->selectRaw('count(distinct subject_id)')->whereColumn('teacher_assignments.classroom_id', 'classrooms.id')->where('teacher_assignments.teacher_id', $teacher->id), 'subjects_count')
            ->selectSub(fn ($query) => $query->from('grades')->join('students', 'grades.student_id', '=', 'students.id')->selectRaw('avg(grades.score)')->whereColumn('students.classroom_id', 'classrooms.id'), 'average_grade')
            ->selectSub(fn ($query) => $query->from('attendance_records')->selectRaw("round(sum(case when status = 'present' then 1 else 0 end) * 100.0 / nullif(count(*), 0), 1)")->whereColumn('attendance_records.classroom_id', 'classrooms.id'), 'attendance_rate')
            ->with(['subjects' => fn ($query) => $query->whereIn('subjects.id', $this->assignedSubjectIds($teacher))])
            ->orderBy('name')
            ->get();

        $assignmentLabels = DB::table('teacher_assignments')
            ->join('teachers', 'teacher_assignments.teacher_id', '=', 'teachers.id')
            ->join('users', 'teachers.user_id', '=', 'users.id')
            ->join('subjects', 'teacher_assignments.subject_id', '=', 'subjects.id')
            ->where('teacher_assignments.teacher_id', $teacher->id)
            ->whereIn('teacher_assignments.classroom_id', $classrooms->pluck('id'))
            ->select('teacher_assignments.classroom_id', 'subjects.name as subject_name')
            ->orderBy('subjects.name')
            ->get()
            ->groupBy('classroom_id');

        $gradeSheets = DB::table('grade_sheets')->where('teacher_id', $teacher->id)->whereIn('classroom_id', $classrooms->pluck('id'))->get()->keyBy('classroom_id');
        $classrooms->each(function ($classroom) use ($assignmentLabels, $gradeSheets): void {
            $classroom->assignment_labels = $assignmentLabels->get($classroom->id, collect())
                ->map(fn ($assignment) => $assignment->subject_name)
                ->values();
            // Only show average if classroom has students
            if ($classroom->students_count > 0) {
                $scores = json_decode($gradeSheets->get($classroom->id)?->scores ?? '', true);
                $classroom->average_grade = is_array($scores) && count($scores) > 0 ? round(collect($scores)->avg(), 1) : $classroom->average_grade;
            } else {
                $classroom->average_grade = null;
            }
        });

        return view('teacher.classes.index', compact('teacher', 'classrooms'));
    }

    public function show(Request $request, Classroom $classroom): View
    {
        $teacher = $this->teacher($request);
        abort_unless($this->assignedClassroomIds($teacher)->contains($classroom->id), 404);

        $subjectIds = $this->assignedSubjectIds($teacher, $classroom->id);
        $classroom->loadCount('students')->load(['academicYear', 'students.user', 'subjects' => fn ($query) => $query->whereIn('subjects.id', $subjectIds)]);
        $studentIds = $classroom->students->pluck('id');
        $attendance = DB::table('attendance_records')->whereIn('student_id', $studentIds)->whereDate('date', today());
        $attendanceToday = (clone $attendance)->where('status', 'present')->count();
        $attendanceTotal = (clone $attendance)->count();
        $attendanceRate = $attendanceTotal > 0 ? round($attendanceToday * 100 / $attendanceTotal) : 0;

        // Only calculate average if classroom has students
        $averageGrade = null;
        if ($classroom->students_count > 0) {
            $gradeSheet = DB::table('grade_sheets')->where('teacher_id', $teacher->id)->where('classroom_id', $classroom->id)->first();
            $sheetScores = json_decode($gradeSheet?->scores ?? '', true);
            $sheetScores = is_array($sheetScores) ? collect($sheetScores)->mapWithKeys(fn ($score, $studentId) => [(int) $studentId => (float) $score]) : collect();
            $averageGrade = $sheetScores->isNotEmpty() ? $sheetScores->avg() : DB::table('grades')->whereIn('student_id', $studentIds)->avg('score');
        } else {
            $gradeSheet = null;
            $sheetScores = collect();
        }

        $studentAverages = DB::table('grades')->whereIn('student_id', $studentIds)->groupBy('student_id')->select('student_id')->selectRaw('avg(score) as average')->pluck('average', 'student_id');
        $studentAverages = $sheetScores->union($studentAverages);
        $studentAttendance = DB::table('attendance_records')->whereIn('student_id', $studentIds)->whereDate('date', today())->pluck('status', 'student_id');
        $classroom->students->each(function ($student) use ($studentAverages, $studentAttendance): void {
            $student->average_grade = $studentAverages->get($student->id);
            $student->attendance_status = $studentAttendance->get($student->id, 'unrecorded');
        });
        $assignmentsCount = DB::table('assignments')->where('classroom_id', $classroom->id)->whereIn('status', ['active', 'published'])->count();
        $activeTab = in_array($request->query('tab'), ['students', 'attendance', 'grades', 'assignments'], true) ? $request->query('tab') : 'students';

        return view('teacher.classes.show', compact('teacher', 'classroom', 'attendanceToday', 'attendanceRate', 'averageGrade', 'assignmentsCount', 'activeTab'));
    }
}
