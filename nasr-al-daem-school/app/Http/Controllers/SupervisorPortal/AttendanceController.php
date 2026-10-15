<?php

namespace App\Http\Controllers\SupervisorPortal;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\StoreDailyAttendanceRequest;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\DailyAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $classes = $request->user()->supervisedClassrooms()->orderBy('name')->get();
        $classId = (int) ($request->query('classroom_id') ?: $classes->first()?->id);
        abort_if($classId && ! $classes->contains('id', $classId), 403);

        $date = $request->date('date', today())?->toDateString() ?: today()->toDateString();
        $students = Student::with('user')
            ->where('classroom_id', $classId ?: null)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (Student $student) => mb_strtolower($student->user?->name ?? ''), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
        $records = Attendance::whereDate('date', $date)
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        return view('supervisor.attendance.index', compact('classes', 'classId', 'date', 'students', 'records'));
    }

    public function store(StoreDailyAttendanceRequest $request, DailyAttendanceService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->save($request->user(), (int) $data['classroom_id'], $data['date'], $data['records']);

        return back()->with('success', 'تم حفظ حضور اليوم بنجاح.');
    }

    public function scan(Request $request, DailyAttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'date' => ['required', 'date'],
            'status' => ['nullable', Rule::enum(AttendanceStatus::class)],
        ]);
        $student = Student::findOrFail($data['student_id']);
        abort_unless((int) $student->classroom_id === (int) $data['classroom_id'], 422, 'الطالب لا ينتمي إلى الفصل المحدد.');

        $service->save($request->user(), (int) $data['classroom_id'], $data['date'], [
            $student->id => ['status' => $data['status'] ?? 'present', 'notes' => 'تم التسجيل عبر QR'],
        ]);

        return response()->json([
            'ok' => true,
            'student' => [
                'id' => $student->id,
                'name' => $student->user()->value('name'),
                'student_number' => $student->student_number,
            ],
            'message' => 'تم تسجيل حضور الطالب.',
        ]);
    }

    public function history(Request $request): View
    {
        $classroomIds = $request->user()->supervisedClassrooms()->pluck('classrooms.id');
        $query = Attendance::with(['student.user', 'student.classroom'])
            ->whereIn('classroom_id', $classroomIds)
            ->when($request->date_from, fn ($query, $value) => $query->whereDate('date', '>=', $value))
            ->when($request->date_to, fn ($query, $value) => $query->whereDate('date', '<=', $value))
            ->when($request->status, fn ($query, $value) => $query->where('status', $value))
            ->when($request->student, fn ($query, $value) => $query->where('student_id', $value));

        return view('supervisor.attendance.history', [
            'records' => $query->latest('date')->paginate(30)->withQueryString(),
            'statuses' => AttendanceStatus::cases(),
        ]);
    }
}
