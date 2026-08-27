<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\Classroom;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);

        $students = Student::query()
            ->with(['user', 'classroom'])
            ->whereIn('classroom_id', $classroomIds)
            ->when($request->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")))
            ->when($request->classroom_id, fn ($q, $v) => $q->where('classroom_id', $v))
            ->orderBy('student_number')
            ->paginate(20)
            ->withQueryString();

        $students->getCollection()->transform(function (Student $student) use ($teacher) {
            // Use overall average (المتوسط) for the students list so المعدل stays
            // in sync with the student portal summary.
            $student->average_percent = $this->overallAverageFor($student->id, $teacher);

            return $student;
        });

        $classrooms = Classroom::whereIn('id', $classroomIds)->get();

        return view('teacher.students.index', compact('students', 'classrooms'));
    }

    public function show(Request $request, Student $student): View
    {
        $teacher = $this->teacher($request);
        abort_unless($this->ownsStudent($teacher, $student->id), 404);

        $student->load(['user', 'classroom.academicYear']);

        $attendance = DB::table('attendance_records')
            ->where('student_id', $student->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->selectRaw("sum(case when status = 'late' then 1 else 0 end) as late")
            ->first();
        $attendanceRate = $attendance->total > 0 ? round($attendance->present * 100 / $attendance->total) : null;

        $assignmentsTotal = DB::table('assignments')->where('teacher_id', $teacher->id)->where('classroom_id', $student->classroom_id)->count();
        $assignmentsSubmitted = DB::table('assignment_submissions')
            ->join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->where('assignments.teacher_id', $teacher->id)
            ->where('assignment_submissions.student_id', $student->id)
            ->whereNotNull('assignment_submissions.submitted_at')
            ->count();

        // Use overall average (المتوسط) so the student file reflects the same
        // المعدل value shown elsewhere.
        $averagePercent = $this->overallAverageFor($student->id, $teacher);

        $recentResults = $this->recentResultsFor($teacher, $student->id, 6);

        return view('teacher.students.show', [
            'student' => $student,
            'attendanceRate' => $attendanceRate,
            'assignmentsTotal' => $assignmentsTotal,
            'assignmentsSubmitted' => $assignmentsSubmitted,
            'averagePercent' => $averagePercent,
            'recentResults' => $recentResults,
        ]);
    }

    private function averageFor($teacher, int $studentId): ?float
    {
        $row = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->where('exams.teacher_id', $teacher->id)
            ->where('grades.student_id', $studentId)
            ->whereNotNull('grades.published_at')
            ->selectRaw('avg(case when exams.total_score > 0 then grades.score * 100.0 / exams.total_score end) as average_percent')
            ->first();

        return $row?->average_percent === null ? null : round((float) $row->average_percent, 1);
    }

    /**
     * Compute overall average percent for a student across all published grades.
     */
    private function overallAverageFor(int $studentId, $teacher = null): ?float
    {
        // If teacher and the student's classroom have a saved grade sheet scores,
        // prefer that stored per-student average (not tied to exams).
        if ($teacher) {
            $classroomId = DB::table('students')->where('id', $studentId)->value('classroom_id');
            if ($classroomId) {
                $record = DB::table('grade_sheets')
                    ->where('teacher_id', $teacher->id)
                    ->where('classroom_id', $classroomId)
                    ->first();

                if ($record && !empty($record->scores)) {
                    $decoded = json_decode($record->scores, true);
                    if (is_array($decoded) && array_key_exists((string)$studentId, $decoded)) {
                        $v = (float) $decoded[(string)$studentId];
                        // Normalize stored value: if fraction (0..1) -> percent; if mistakenly scaled (>100) divide by 100 until reasonable
                        if ($v > 0 && $v <= 1) {
                            $v = $v * 100.0;
                        }
                        while ($v > 100) {
                            $v = $v / 100.0;
                        }
                        return round($v, 1);
                    }
                }
            }
        }
        $row = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->where('grades.student_id', $studentId)
            ->whereNotNull('grades.published_at')
            ->selectRaw('avg(case when exams.total_score > 0 then grades.score * 100.0 / exams.total_score end) as average_percent')
            ->first();

        if ($row?->average_percent !== null) {
            return round((float) $row->average_percent, 1);
        }

        // Fallback: if grades exist but are not linked/compatible with exams,
        // compute simple average of the `score` column (assumed to be percent).
        $raw = DB::table('grades')
            ->where('student_id', $studentId)
            ->selectRaw('avg(score) as average_percent')
            ->first();

        return $raw?->average_percent === null ? null : round((float) $raw->average_percent, 1);
    }

    private function recentResultsFor($teacher, int $studentId, int $limit)
    {
        $examResults = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->where('exams.teacher_id', $teacher->id)
            ->where('grades.student_id', $studentId)
            ->whereNotNull('grades.published_at')
            ->select(
                'exams.title as label',
                'grades.score',
                'exams.total_score as max_score',
                'grades.published_at as date',
                DB::raw("'exam' as kind")
            )
            ->get();

        $assignmentResults = DB::table('assignment_submissions')
            ->join('assignments', 'assignment_submissions.assignment_id', '=', 'assignments.id')
            ->where('assignments.teacher_id', $teacher->id)
            ->where('assignment_submissions.student_id', $studentId)
            ->whereNotNull('assignment_submissions.score')
            ->select(
                'assignments.title as label',
                'assignment_submissions.score',
                'assignments.max_score',
                'assignment_submissions.updated_at as date',
                DB::raw("'homework' as kind")
            )
            ->get();

        return $examResults->concat($assignmentResults)
            ->sortByDesc('date')
            ->take($limit)
            ->values();
    }
}
