<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\NotificationDestination;
use App\Services\ParentPortalContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function show(Request $request, Student $student, Lesson $lesson, ParentPortalContext $context, NotificationDestination $destinations): View
    {
        $context->assertChild($context->guardian($request), $student);
        abort_unless($student->status === 'active' && $destinations->visibleLearning('lesson', $lesson, $student), 404);
        $lesson->load(['subject', 'unit']);

        return view('parent.lessons.show', compact('student', 'lesson'));
    }
}
