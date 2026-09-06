<?php

namespace App\Http\Controllers\StudentPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherStudentNote;
use Illuminate\Http\RedirectResponse;
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
            'subjects' => $this->subjectsFor($student),
            'todaysSchedule' => $this->todaysScheduleFor($student),
            'announcements' => $this->announcementsFor($student, 4),
            'teacherNotes' => TeacherStudentNote::with('teacher.user')->where('student_id', $student->id)->latest()->limit(4)->get(),
        ]);
    }

    public function results(Request $request): View
    {
        $student = $this->student($request);

        return view('student.results', [
            'student' => $student,
            'summary' => $this->summaryFor($student),
            'recentGrades' => $this->recentGradesFor($student, 10),
            'automaticResults' => $this->automaticResultsFor($student, 20),
            'publications' => app(\App\Services\ResultPublicationService::class)->publications($student),
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

    public function attendance(Request $request): View
    {
        $student = $this->student($request);
        $records = DB::table('attendance_records')->where('student_id', $student->id)->latest('date')->paginate(30);

        return view('student.attendance', compact('student', 'records'));
    }

    public function schedule(Request $request): View
    {
        $student = $this->student($request);
        $schedule = DB::table('schedules')->join('subjects', 'schedules.subject_id', '=', 'subjects.id')
            ->join('teachers', 'schedules.teacher_id', '=', 'teachers.id')->join('users', 'teachers.user_id', '=', 'users.id')
            ->where('schedules.classroom_id', $student->classroom_id)
            ->select('schedules.*', 'subjects.name as subject', 'users.name as teacher')->orderBy('day_of_week')->orderBy('starts_at')->get();

        return view('student.schedule', compact('student', 'schedule'));
    }

    public function notifications(Request $request): View
    {
        $student = $this->student($request);
        $notifications = $request->user()->notifications()->latest()->paginate(30);
        $unreadCount = $request->user()->unreadNotifications()->count();
        $announcements = $this->announcementsFor($student, 20);

        return view('student.notifications', compact('student', 'notifications', 'unreadCount', 'announcements'));
    }

    public function markNotificationRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return back()->with('success', 'تم تعليم الإشعار كمقروء.');
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

        $savedGradeValues = app(\App\Services\ResultPublicationService::class)->visibleResults($student)->filter(fn ($r) => $r->total_score > 0)->map(fn ($r) => $r->score * 100 / $r->total_score);

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
        return app(\App\Services\ResultPublicationService::class)->visibleResults($student)->take($limit)->values();
    }

    private function automaticResultsFor(Student $student, int $limit): Collection
    {
        return collect();
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
                $query->whereIn('audience', ['all', 'students'])
                    ->when($student->classroom_id, fn ($audience) => $audience->orWhere(
                        fn ($classroom) => $classroom->where('audience', 'classroom')->where('classroom_id', $student->classroom_id),
                    ));
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
