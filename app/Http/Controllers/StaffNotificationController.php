<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\ParentNotificationService;
use App\Services\StaffAccess;
use Illuminate\Http\Request;

class StaffNotificationController extends Controller
{
    public function create(Request $r)
    {
        abort_unless($r->user()->hasPermission('parent-notifications.send'), 403);
        $students = app(StaffAccess::class)->students($r->user(), 'parent-notifications.send')->with(['user', 'classroom', 'guardians.user'])->where('status', 'active')->get();

        return view('staff.notifications.create', compact('students'));
    }

    public function store(Request $r, ParentNotificationService $service)
    {
        abort_unless($r->user()->hasPermission('parent-notifications.send'), 403);
        $d = $r->validate(['student_id' => 'required|integer|exists:students,id', 'guardian_id' => 'required|integer|exists:guardians,id', 'title' => 'required|string|max:255', 'message' => 'required|string|max:5000']);
        $student = app(StaffAccess::class)->students($r->user(), 'parent-notifications.send')->with('guardians')->findOrFail($d['student_id']);
        abort_unless($student->guardians->contains('id', $d['guardian_id']), 422, 'ولي الأمر غير مرتبط بهذا الطالب.');
        $guardian = $student->guardians->firstWhere('id', $d['guardian_id']);
        $service->send($guardian->user, ['title' => $d['title'], 'body' => $d['message'], 'category' => 'staff_notification']);
        AuditService::record('created', 'parent_notifications', $student, [], ['guardian_id' => $guardian->id]);

        return back()->with('success', 'تم إرسال الإشعار.');
    }
}
