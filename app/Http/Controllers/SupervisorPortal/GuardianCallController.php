<?php

namespace App\Http\Controllers\SupervisorPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupervisorPortal\Concerns\AuthorizesAssignedStudents;
use App\Models\GuardianCall;
use App\Models\Student;
use App\Services\AuditService;
use App\Services\ParentFollowupNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GuardianCallController extends Controller
{
    use AuthorizesAssignedStudents;

    public function index(Request $request): View
    {
        $ids = $request->user()->supervisedClassrooms()->pluck('classrooms.id');

        return view('supervisor.calls.index', [
            'calls' => GuardianCall::with('student.user')->whereHas('student', fn ($query) => $query->whereIn('classroom_id', $ids))->latest()->paginate(30),
            'students' => Student::with(['user', 'classroom'])->whereIn('classroom_id', $ids)->where('status', 'active')->get(),
        ]);
    }

    public function storeDirect(Request $request, ParentFollowupNotificationService $notifications): RedirectResponse
    {
        $request->validate(['student_id' => ['required', 'integer', 'exists:students,id']]);

        return $this->store($request, Student::findOrFail($request->integer('student_id')), $notifications);
    }

    public function store(Request $request, Student $student, ParentFollowupNotificationService $notifications): RedirectResponse
    {
        $this->authorizeStudent($request, $student);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'], 'guardian_visible_details' => ['nullable', 'string', 'max:5000'],
            'meeting_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $parentCount = DB::transaction(function () use ($data, $student, $request, $notifications): int {
            $call = GuardianCall::create($data + ['student_id' => $student->id, 'supervisor_id' => $request->user()->id, 'status' => 'pending', 'requested_date' => today()]);
            $parentCount = $notifications->sendCall($call);
            if ($parentCount > 0) {
                $call->update(['status' => 'notified']);
            }
            AuditService::record('created', 'guardian_calls', $call);

            return $parentCount;
        });

        return back()->with('success', $parentCount > 0 ? 'تم إنشاء الاستدعاء وإبلاغ ولي الأمر.' : 'تم إنشاء الاستدعاء، لكن لا يوجد ولي أمر مرتبط ونشط لهذا الطالب.');
    }

    public function update(Request $request, GuardianCall $guardianCall): RedirectResponse
    {
        $this->authorizeStudent($request, $guardianCall->student);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'notified', 'scheduled', 'completed', 'cancelled'])],
            'meeting_date' => ['nullable', 'date', 'after_or_equal:requested_date'], 'guardian_response' => ['nullable', 'string', 'max:5000'],
        ]);
        $old = $guardianCall->getAttributes();
        $guardianCall->update($data + ['resolved_at' => in_array($data['status'], ['completed', 'cancelled']) ? now() : null]);
        AuditService::record('updated', 'guardian_calls', $guardianCall, $old);

        return back()->with('success', 'تم تحديث حالة الاستدعاء.');
    }
}
