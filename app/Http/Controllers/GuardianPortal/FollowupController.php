<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\GuardianCall;
use App\Models\StudentNote;
use App\Services\ParentFollowupNotificationService;
use App\Services\ParentPortalContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FollowupController extends Controller
{
    public function __construct(private readonly ParentPortalContext $context, private readonly ParentFollowupNotificationService $notifications) {}

    public function notes(Request $request): View
    {
        $unreadIds = $this->notifications->unreadIds($request->user(), 'student_note');
        $unreadNotes = StudentNote::whereIn('id', $unreadIds)->latest()->get(['student_id']);
        [$children, $selectedStudent] = $this->studentContext($request, $request->filled('student') ? null : $unreadNotes->first()?->student_id);

        return view('parent.student-followup', [
            'children' => $children, 'selectedStudent' => $selectedStudent,
            'notes' => StudentNote::with('guardianCalls')->where('student_id', $selectedStudent?->id ?? 0)
                ->where('visibility', 'guardian')->latest()->orderByDesc('id')->paginate(20)->withQueryString(),
            'unreadIds' => $unreadIds,
            'followupUnreadByStudent' => $unreadNotes->countBy('student_id'),
        ]);
    }

    public function note(Request $request, StudentNote $note): View
    {
        abort_unless($note->visibility === 'guardian', 404);
        $this->context->assertChild($this->context->guardian($request), $note->student);
        [$children, $selectedStudent] = $this->studentContext($request, $note->student_id);
        $note->load('supervisor');
        $calls = $note->guardianCalls()->with('note')->where('student_id', $note->student_id)->latest()->get();
        $this->notifications->markRead($request->user(), $note);

        return view('parent.followup.note', compact('children', 'selectedStudent', 'note', 'calls'));
    }

    public function calls(Request $request): View
    {
        $unreadIds = $this->notifications->unreadIds($request->user(), 'guardian_call');
        $unreadCalls = GuardianCall::whereIn('id', $unreadIds)->latest()->get(['student_id']);
        [$children, $selectedStudent] = $this->studentContext($request, $request->filled('student') ? null : $unreadCalls->first()?->student_id);

        return view('parent.guardian-calls', [
            'children' => $children, 'selectedStudent' => $selectedStudent,
            'calls' => GuardianCall::with('note')->where('student_id', $selectedStudent?->id ?? 0)
                ->latest()->orderByDesc('id')->paginate(20)->withQueryString(),
            'unreadIds' => $unreadIds,
            'followupUnreadByStudent' => $unreadCalls->countBy('student_id'),
        ]);
    }

    public function call(Request $request, GuardianCall $guardianCall): View
    {
        $this->context->assertChild($this->context->guardian($request), $guardianCall->student);
        [$children, $selectedStudent] = $this->studentContext($request, $guardianCall->student_id);
        $guardianCall->load(['supervisor', 'note']);
        $note = $guardianCall->note?->visibility === 'guardian' && $guardianCall->note->student_id === $guardianCall->student_id
            ? $guardianCall->note : null;
        $this->notifications->markRead($request->user(), $guardianCall);

        return view('parent.followup.call', ['children' => $children, 'selectedStudent' => $selectedStudent, 'call' => $guardianCall, 'note' => $note]);
    }

    private function studentContext(Request $request, ?int $studentId = null): array
    {
        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);

        return [$children, $this->context->selectedStudent($guardian, $children, $studentId ?? $request->query('student'))];
    }
}
