<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ParentPortalContext;
use App\Services\TeacherReportDeliveryService;
use Illuminate\Http\Request;

class ReceivedTeacherReportController extends Controller
{
    public function index(Request $request, TeacherReportDeliveryService $reports)
    {
        $this->authorizeRole($request);
        $user = $request->user();
        $children = collect();
        $selectedStudent = null;
        if ($user->isParent()) {
            $context = app(ParentPortalContext::class);
            $guardian = $context->guardian($request);
            abort_unless($guardian->status === 'active', 404);
            $children = $context->children($guardian)->filter(fn ($child) => $child->status === 'active' && $child->user?->isStudent())->values();
            $selectedStudent = $context->selectedStudent($guardian, $children, $request->query('student'));
        }
        $deliveries = $reports->inbox($user, $selectedStudent?->id);
        $layout = $user->role.'.layout';

        return response()->view('shared.teacher-reports.index', compact('deliveries', 'children', 'selectedStudent', 'layout'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $delivery, TeacherReportDeliveryService $reports)
    {
        $this->authorizeRole($request);
        $received = $reports->received($request->user(), $delivery);
        $builder = $received['builder'];
        $delivery = $received['delivery'];
        $selectedStudent = $delivery->student_id ? Student::find($delivery->student_id) : null;

        return response()->view('shared.teacher-reports.show', compact('builder', 'delivery', 'selectedStudent'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function authorizeRole(Request $request): void
    {
        abort_unless($request->user()->status === 'active' && in_array($request->user()->role, ['student', 'parent', 'admin'], true), 403);
    }
}
