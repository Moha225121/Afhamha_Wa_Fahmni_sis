<?php

namespace App\Http\Controllers\SupervisorPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SupervisorPortal\Concerns\AuthorizesAssignedStudents;
use App\Models\GuardianCall;
use App\Models\Student;
use App\Models\StudentNote;
use App\Services\AuditService;
use App\Services\ParentFollowupNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentNoteController extends Controller
{
    use AuthorizesAssignedStudents;

    public function index(Request $request): View
    {
        $ids = $request->user()->supervisedClassrooms()->pluck('classrooms.id');

        return view('supervisor.notes.index', [
            'notes' => StudentNote::with('student.user')->whereHas('student', fn ($query) => $query->whereIn('classroom_id', $ids))->latest()->paginate(30),
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
            'type' => ['required', Rule::in(['general', 'health', 'psychological', 'behavioral', 'administrative'])],
            'title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::in(['internal', 'guardian'])], 'requires_guardian_call' => ['nullable', 'boolean'],
        ]);
        $parentCount = DB::transaction(function () use ($data, $student, $request, $notifications): int {
            $note = StudentNote::create($data + ['student_id' => $student->id, 'supervisor_id' => $request->user()->id]);
            AuditService::record('created', 'student_notes', $note);
            $parentCount = $notifications->sendNote($note);
            if ($note->requires_guardian_call) {
                $call = GuardianCall::create([
                    'student_id' => $student->id, 'supervisor_id' => $request->user()->id, 'student_note_id' => $note->id,
                    'category' => $note->type, 'reason' => $note->title, 'details' => $note->content,
                    'guardian_visible_details' => $note->visibility === 'guardian' ? $note->content : null,
                    'status' => 'pending', 'requested_date' => today(),
                ]);
                $parentCount = $notifications->sendCall($call);
                if ($parentCount > 0) {
                    $call->update(['status' => 'notified']);
                }
                AuditService::record('created', 'guardian_calls', $call);
            }

            return $parentCount;
        });
        $message = $parentCount > 0 ? 'تم حفظ الملاحظة وإشعار ولي الأمر.' : 'تم حفظ ملاحظة المتابعة.';
        if (($data['visibility'] === 'guardian' || ! empty($data['requires_guardian_call'])) && $parentCount === 0) {
            $message .= ' لا يوجد ولي أمر مرتبط ونشط لهذا الطالب لإبلاغه.';
        }

        return back()->with('success', $message);
    }
}
