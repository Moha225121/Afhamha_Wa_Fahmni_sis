<?php

namespace App\Http\Controllers\StudentPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Student;
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
            'recentGrades' => $this->recentGradesFor($student, 3),
            'upcomingExams' => $this->upcomingExamsFor($student, 4),
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

        $grades = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->where('grades.student_id', $student->id)
            ->whereNotNull('grades.published_at')
            ->selectRaw('count(*) as total')
            ->selectRaw('avg(case when exams.total_score > 0 then grades.score * 100.0 / exams.total_score end) as average_percent')
            ->first();

        return [
            'attendance_total' => (int) ($attendance->total ?? 0),
            'present' => (int) ($attendance->present ?? 0),
            'absent' => (int) ($attendance->absent ?? 0),
            'late' => (int) ($attendance->late ?? 0),
            'published_grades' => (int) ($grades->total ?? 0),
            'average_percent' => $grades?->average_percent === null ? null : round((float) $grades->average_percent, 1),
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

    private function upcomingExamsFor(Student $student, int $limit): Collection
    {
        return DB::table('exams')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('exams.classroom_id', $student->classroom_id)
            ->whereIn('exams.status', ['scheduled', 'published'])
            ->where('exams.starts_at', '>=', now())
            ->select([
                'exams.id',
                'exams.title',
                'exams.starts_at',
                'subjects.name as subject',
            ])
            ->orderBy('exams.starts_at')
            ->limit($limit)
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
                    $query->orWhere('classroom_id', $student->classroom_id);
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
