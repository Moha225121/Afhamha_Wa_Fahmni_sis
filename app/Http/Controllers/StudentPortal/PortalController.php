<?php

namespace App\Http\Controllers\StudentPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $student = $this->student($request);

        return view('student.dashboard', [
            'student' => $student,
            'summary' => $this->summaryFor($student),
            'subjects' => $this->subjectsFor($student),
            'todaysSchedule' => $this->todaysScheduleFor($student),
            'announcements' => $this->announcementsFor($student, 4),
        ]);
    }

    public function results(Request $request): View
    {
        $student = $this->student($request);

        return view('student.results', [
            'student' => $student,
            'summary' => $this->summaryFor($student),
            'recentGrades' => $this->recentGradesFor($student, 10),
        ]);
    }

    public function messages(Request $request): View
    {
        $student = $this->student($request);

        return view('student.messages', [
            'student' => $student,
            'announcements' => $this->announcementsFor($student, 20),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('student.profile', ['student' => $this->student($request)]);
    }

    private function student(Request $request): Student
    {
        return $request->user()
            ->student()
            ->with(['user', 'classroom.academicYear'])
            ->firstOrFail();
    }

    private function summaryFor(Student $student): array
    {
        $attendance = DB::table('attendance_records')
            ->where('student_id', $student->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->selectRaw("sum(case when status = 'late' then 1 else 0 end) as late")
            ->first();

        $savedGradeValues = collect();
        $gradeSheets = DB::table('grade_sheets')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('teacher_assignments')
                    ->whereColumn('teacher_assignments.teacher_id', 'grade_sheets.teacher_id')
                    ->whereColumn('teacher_assignments.classroom_id', 'grade_sheets.classroom_id');
            })
            ->where('grade_sheets.classroom_id', $student->classroom_id)
            ->select('grade_sheets.scores')
            ->pluck('scores');

        foreach ($gradeSheets as $scores) {
            $scores = json_decode($scores ?? '{}', true);

            if (is_array($scores) && array_key_exists((string) $student->id, $scores)) {
                $studentScore = $scores[(string) $student->id];

                if (is_array($studentScore)) {
                    $studentScore = collect($studentScore)
                        ->filter(fn ($value): bool => is_numeric($value))
                        ->average();
                }

                if (is_numeric($studentScore)) {
                    $savedGradeValues->push((float) $studentScore);
                }
            }
        }

        return [
            'attendance_total' => (int) ($attendance->total ?? 0),
            'present' => (int) ($attendance->present ?? 0),
            'absent' => (int) ($attendance->absent ?? 0),
            'late' => (int) ($attendance->late ?? 0),
            'published_grades' => $savedGradeValues->count(),
            'average_percent' => $savedGradeValues->isEmpty() ? null : round((float) $savedGradeValues->average(), 1),
        ];
    }

    private function recentGradesFor(Student $student, int $limit): Collection
    {
        return DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('grades.student_id', $student->id)
            ->whereNotNull('grades.published_at')
            ->select([
                'grades.score',
                'grades.published_at',
                'exams.title',
                'exams.total_score',
                'subjects.name as subject',
            ])
            ->latest('grades.published_at')
            ->limit($limit)
            ->get();
    }

    private function subjectsFor(Student $student): Collection
    {
        if (! $student->classroom_id) {
            return collect();
        }

        return Subject::query()
            ->where('status', 'active')
            ->whereHas('classrooms', fn ($query) => $query->whereKey($student->classroom_id))
            ->orderBy('name')
            ->get();
    }

    private function todaysScheduleFor(Student $student): Collection
    {
        if (! $student->classroom_id) {
            return collect();
        }

        return DB::table('schedules')
            ->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->join('teachers', 'schedules.teacher_id', '=', 'teachers.id')
            ->join('users', 'teachers.user_id', '=', 'users.id')
            ->where('schedules.classroom_id', $student->classroom_id)
            ->where('schedules.day_of_week', now()->dayOfWeek)
            ->where('subjects.status', 'active')
            ->select([
                'schedules.starts_at',
                'schedules.ends_at',
                'schedules.room',
                'subjects.name as subject',
                'users.name as teacher',
            ])
            ->orderBy('schedules.starts_at')
            ->get();
    }

    private function announcementsFor(Student $student, int $limit): Collection
    {
        return Announcement::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($student): void {
                $query->whereIn('audience', ['all', 'students']);

                if ($student->classroom_id) {
                    $query->orWhere(function ($classroomQuery) use ($student): void {
                        $classroomQuery
                            ->where('audience', 'classroom')
                            ->where('classroom_id', $student->classroom_id);
                    });
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
