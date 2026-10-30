<?php

namespace App\Http\Controllers;

use App\Models\TeacherMessageReceipt;
use App\Services\ParentMessageScope;
use App\Services\ParentStudentSession;
use App\Services\PortalMessengerService;
use App\Services\TeacherMessageReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TeacherMessageController extends Controller
{
    public function show(Request $request, string $notification, TeacherMessageReceiptService $receipts): Response
    {
        [$receipt, $record] = $this->ownedMessage($request, $notification, $receipts);
        $receipts->delivered($receipt);

        return response()->view('shared.teacher-message', [
            'notification' => $record, 'receipt' => $receipt->refresh(), 'layout' => $request->user()->role.'.layout',
            'senderName' => $receipt->teacher?->name ?? 'المعلم',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function viewed(Request $request, string $notification, TeacherMessageReceiptService $receipts): JsonResponse
    {
        [$receipt, $record] = $this->ownedMessage($request, $notification, $receipts);
        $receipts->viewed($receipt, $record);

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    private function ownedMessage(Request $request, string $notification, TeacherMessageReceiptService $receipts): array
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && in_array($user->role, ['student', 'parent'], true), 403);
        abort_unless($receipts->available(), 404);
        $profile = $user->role === 'student' ? $user->student : $user->guardian;
        abort_unless($profile?->status === 'active', 403);
        if ($user->role === 'student' && ($request->session()->has(ParentStudentSession::PARENT_ID) || $request->session()->has(ParentStudentSession::STUDENT_ID))) {
            abort_unless(app(ParentStudentSession::class)->validActiveSession(
                (int) $request->session()->get(ParentStudentSession::PARENT_ID),
                (int) $request->session()->get(ParentStudentSession::STUDENT_ID), (int) $user->id,
            ), 403);
        }
        $receipt = TeacherMessageReceipt::whereKey($notification)->where('recipient_user_id', $user->id)->with('teacher:id,name')->firstOrFail();
        $record = $receipts->ownedNotification($user, $notification);
        abort_unless($record, 404);
        if ($user->role === 'parent') {
            $selectedStudent = app(ParentMessageScope::class)->selected($request);
            abort_unless($selectedStudent && app(ParentMessageScope::class)->includes($record->data, $selectedStudent->id), 404);
        }

        return [$receipt, $record];
    }

    public function delivered(string $notification, TeacherMessageReceiptService $receipts): JsonResponse
    {
        if (app(PortalMessengerService::class)->acknowledgeDelivery($notification)) {
            return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
        }
        abort_unless($receipts->available(), 404);
        $receipt = TeacherMessageReceipt::whereKey($notification)->with('recipient')->firstOrFail();
        $recipient = $receipt->recipient;
        abort_unless($recipient && in_array($recipient->role, ['student', 'parent'], true)
            && $receipts->ownedNotification($recipient, $notification), 404);
        $receipts->delivered($receipt);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
