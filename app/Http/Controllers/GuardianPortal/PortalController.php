<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function dashboard(Request $request): View
    {
        $guardian = $this->guardian($request);
        $children = $this->childrenFor($guardian);
        $selectedStudent = $this->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.dashboard', [
            'guardian' => $guardian,
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $this->summaryFor($selectedStudent),
            'recentGrades' => $this->recentGradesFor($selectedStudent, 3),
            'announcements' => $this->announcementsFor($children, 4),
        ]);
    }

    public function children(Request $request): View
    {
        $guardian = $this->guardian($request);

        return view('parent.children.index', [
            'guardian' => $guardian,
            'children' => $this->childrenFor($guardian),
        ]);
    }

    public function child(Request $request, Student $student): View
    {
        $guardian = $this->guardian($request);
        abort_unless($guardian->students()->whereKey($student->id)->exists(), 404);

        $student->load(['user', 'classroom.academicYear']);

        return view('parent.children.show', [
            'guardian' => $guardian,
            'student' => $student,
            'summary' => $this->summaryFor($student),
            'recentGrades' => $this->recentGradesFor($student, 5),
            'latestAttendance' => DB::table('attendance_records')->where('student_id', $student->id)->latest('date')->first(),
        ]);
    }

    public function results(Request $request): View
    {
        $guardian = $this->guardian($request);
        $children = $this->childrenFor($guardian);
        $selectedStudent = $this->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.results', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'summary' => $this->summaryFor($selectedStudent),
            'recentGrades' => $this->recentGradesFor($selectedStudent, 8),
        ]);
    }

    public function messages(Request $request): View
    {
        $guardian = $this->guardian($request);
        $children = $this->childrenFor($guardian);

        return view('parent.messages', [
            'children' => $children,
            'announcements' => $this->announcementsFor($children, 20),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('parent.profile', ['guardian' => $this->guardian($request)]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($validated);

        return back()->with('success', 'تم حفظ الملف الشخصي.');
    }

    public function more(Request $request): View
    {
        return view('parent.more', ['guardian' => $this->guardian($request)]);
    }

    private function guardian(Request $request): Guardian
    {
        return $request->user()->guardian()->with('user')->firstOrFail();
    }

    private function childrenFor(Guardian $guardian): Collection
    {
        return $guardian->students()
            ->with(['user', 'classroom.academicYear'])
            ->orderBy('student_number')
            ->get();
    }

    private function selectedStudent(Guardian $guardian, Collection $children, mixed $studentId): ?Student
    {
        if ($studentId === null || $studentId === '') {
            return $children->first();
        }

        $student = $guardian->students()
            ->with(['user', 'classroom.academicYear'])
            ->whereKey((int) $studentId)
            ->first();

        abort_unless($student, 404);

        return $student;
    }

    private function summaryFor(?Student $student): array
    {
        if (! $student) {
            return [
                'attendance_total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'published_grades' => 0,
                'average_percent' => null,
            ];
        }

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

    private function recentGradesFor(?Student $student, int $limit): Collection
    {
        if (! $student) {
            return collect();
        }

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

    private function announcementsFor(Collection $children, int $limit): Collection
    {
        $classroomIds = $children->pluck('classroom_id')->filter()->unique()->values();

        return Announcement::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($classroomIds): void {
                $query->whereIn('audience', ['all', 'parent', 'parents']);

                if ($classroomIds->isNotEmpty()) {
                    $query->orWhereIn('classroom_id', $classroomIds);
                }
            })
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
