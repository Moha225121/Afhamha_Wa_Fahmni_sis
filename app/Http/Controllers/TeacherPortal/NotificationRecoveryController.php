<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Services\TeacherMessageRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationRecoveryController extends Controller
{
    public function __invoke(Request $request, string $dispatch, TeacherMessageRecoveryService $recovery): RedirectResponse
    {
        $input = $request->validate([
            'after' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'payload' => ['prohibited'], 'job_id' => ['prohibited'], 'teacher_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ]);
        $result = $recovery->recover($request->user(), $dispatch, (int) ($input['after'] ?? 0));

        return redirect()->route('teacher.notifications.show', $dispatch)
            ->with('message_recovery', ['dispatch' => $dispatch] + $result);
    }
}
