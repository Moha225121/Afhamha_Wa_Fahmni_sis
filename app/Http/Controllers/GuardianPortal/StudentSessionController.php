<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentPortalContext;
use App\Services\ParentStudentSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentSessionController extends Controller
{
    public function start(Request $request, Student $student, ParentPortalContext $context, ParentStudentSession $sessions): RedirectResponse
    {
        $guardian = $context->guardian($request);
        abort_unless($sessions->belongsTo($guardian, $student) && $sessions->canOpen($student), 404);
        abort_unless($student->user?->isStudent(), 404);

        $request->session()->put(ParentStudentSession::PARENT_ID, $request->user()->id);
        $request->session()->put(ParentStudentSession::STUDENT_ID, $student->id);
        Auth::login($student->user);
        $request->session()->regenerate();

        return redirect()->route('student.dashboard')->with('success', 'تم فتح مساحة الطالب. يمكنك العودة إلى حساب ولي الأمر من الشريط أعلى الصفحة.');
    }

    public function returnToParent(Request $request, ParentStudentSession $sessions): RedirectResponse
    {
        $parentId = (int) $request->session()->get(ParentStudentSession::PARENT_ID);
        $studentId = (int) $request->session()->get(ParentStudentSession::STUDENT_ID);
        abort_unless($parentId && $studentId && $sessions->matchesCurrentStudent($studentId, (int) $request->user()->id), 403);

        $parent = User::whereKey($parentId)->where('role', 'parent')->where('status', 'active')->firstOrFail();
        Auth::login($parent);
        $request->session()->forget([ParentStudentSession::PARENT_ID, ParentStudentSession::STUDENT_ID]);
        $request->session()->regenerate();

        return redirect()->route('parent.dashboard')->with('success', 'تمت العودة إلى حساب ولي الأمر.');
    }
}
