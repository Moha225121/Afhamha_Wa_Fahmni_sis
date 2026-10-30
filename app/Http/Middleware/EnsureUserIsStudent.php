<?php

namespace App\Http\Middleware;

use App\Services\ParentStudentSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        if ($request->routeIs('student.return-to-parent')) {
            abort_unless($request->user()?->role === 'student', 403);

            return $next($request);
        }

        abort_unless($request->user()?->isStudent() && $student?->status === 'active', 403);

        $parentId = $request->session()->get(ParentStudentSession::PARENT_ID);
        $studentId = $request->session()->get(ParentStudentSession::STUDENT_ID);
        if ($parentId || $studentId) {
            abort_unless($parentId && $studentId && app(ParentStudentSession::class)->validActiveSession((int) $parentId, (int) $studentId, (int) $request->user()->id), 403);
        }

        return $next($request);
    }
}
