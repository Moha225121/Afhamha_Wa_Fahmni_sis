<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use App\Models\Student;
use App\Models\TeacherStudentNote;
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
            $student->average_percent = $this->subjectAverageFor($student->id, $teacher);

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

        $subjectNames = DB::table('teacher_assignments')
            ->join('subjects', 'teacher_assignments.subject_id', '=', 'subjects.id')
            ->where('teacher_assignments.teacher_id', $teacher->id)
            ->where('teacher_assignments.classroom_id', $student->classroom_id)
            ->orderBy('subjects.name')
            ->pluck('subjects.name')
            ->unique()
            ->values();
        $averagePercent = $this->subjectAverageFor($student->id, $teacher);

        $recentResults = $this->recentResultsFor($teacher, $student->id, 6);
        $notes = TeacherStudentNote::where('teacher_id', $teacher->id)->where('student_id', $student->id)->latest()->limit(5)->get();

        return view('teacher.students.show', [
            'student' => $student,
            'attendanceRate' => $attendanceRate,
            'assignmentsTotal' => $assignmentsTotal,
            'assignmentsSubmitted' => $assignmentsSubmitted,
            'averagePercent' => $averagePercent,
            'subjectLabel' => $subjectNames->join('، '),
            'recentResults' => $recentResults,
            'notes' => $notes,
        ]);
    }

    public function noteStore(Request $request, Student $student): RedirectResponse
    {
        $teacher = $this->teacher($request);
        abort_unless($this->ownsStudent($teacher, $student->id), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        TeacherStudentNote::create(['teacher_id' => $teacher->id, 'student_id' => $student->id, 'body' => $data['body']]);

        return back()->with('success', 'تم إرسال الملاحظة وحفظها.');
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
     * Compute overall average percent for a student across assigned subjects only.
     */
    private function subjectAverageFor(int $studentId, $teacher = null): ?float
    {
        // A teacher's saved sheet represents the subject average for that classroom.
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

        // Get the classroom for this student
        $classroomId = DB::table('students')->where('id', $studentId)->value('classroom_id');

        // Get only the subject IDs assigned to this teacher for this classroom
        $assignedSubjectIds = DB::table('teacher_assignments')
            ->where('teacher_id', $teacher->id)
            ->where('classroom_id', $classroomId)
            ->pluck('subject_id')
            ->toArray();

        if (empty($assignedSubjectIds)) {
            return null;
        }

        $row = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->whereIn('exams.subject_id', $assignedSubjectIds)
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
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->whereIn('exams.subject_id', $assignedSubjectIds)
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
