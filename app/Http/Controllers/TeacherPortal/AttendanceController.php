<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);

        $date = $request->date ?: now()->toDateString();
        $classroomId = $request->classroom_id && in_array((int) $request->classroom_id, $classroomIds->all(), true)
            ? (int) $request->classroom_id
            : null;

        $students = Student::with('user')
            ->when($classroomId, fn ($q) => $q->where('classroom_id', $classroomId), fn ($q) => $q->whereIn('classroom_id', $classroomIds))
            ->where('status', 'active')
            ->orderBy('student_number')
            ->get();

        $records = DB::table('attendance_records')->whereDate('date', $date)->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');
        $attendanceSummary = collect(['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0])->merge($records->countBy('status'));

        $classrooms = Classroom::whereIn('id', $classroomIds)->get();

        return view('teacher.attendance.index', compact('students', 'records', 'date', 'classroomId', 'classrooms', 'attendanceSummary'));
    }

    public function store(Request $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'classroom_id' => ['nullable', 'integer'],
            'records' => ['required', 'array'],
            'records.*' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
        ]);

        DB::transaction(function () use ($data, $request, $classroomIds) {
            foreach ($data['records'] as $studentId => $status) {
                $student = Student::findOrFail($studentId);
                abort_unless($classroomIds->contains($student->classroom_id), 403, 'لا يمكنك تسجيل حضور طالب خارج صفوفك.');

                DB::table('attendance_records')->updateOrInsert(
                    ['student_id' => $student->id, 'date' => $data['date']],
                    ['classroom_id' => $student->classroom_id, 'status' => $status, 'recorded_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            AuditService::record('recorded', 'attendance');
        });

        return redirect()->route('teacher.attendance.index', [
            'date' => $data['date'],
            'classroom_id' => $data['classroom_id'] ?? null,
        ])->with('success', 'تم حفظ الحضور.');
    }
}
