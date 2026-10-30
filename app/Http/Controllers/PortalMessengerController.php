<?php

namespace App\Http\Controllers;

use App\Http\Controllers\GuardianPortal\PortalController;
use App\Rules\NoNullBytes;
use App\Services\ParentMessageScope;
use App\Services\PortalMessengerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PortalMessengerController extends Controller
{
    public function index(Request $request, PortalMessengerService $messenger): Response
    {
        if ($request->user()->isParent()) {
            $legacy = app(PortalController::class)->messages($request);

            return response()->view('parent.messages', $legacy->getData() + ['inbox' => $messenger->inbox($request)])
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->view('shared.messenger', ['layout' => $request->user()->role.'.layout', 'inbox' => $messenger->inbox($request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, PortalMessengerService $messenger): RedirectResponse
    {
        $role = $request->user()->role;
        abort_unless(in_array($role, ['parent', 'teacher', 'student'], true), 403);
        try {
            $fields = Validator::make($request->all(), [
                'body' => ['required', 'string', new NoNullBytes], 'client_request_id' => ['required', 'uuid'],
                'teacher_id' => [$role === 'parent' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'student_id' => [$role === 'parent' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'conversation_id' => [$role === 'teacher' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'recipient_id' => [$role === 'student' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'sender_id' => ['prohibited'],
            ])->validate();
            if ($role === 'parent') {
                $selected = app(ParentMessageScope::class)->selected($request);
                abort_unless($selected && (int) $selected->id === (int) $fields['student_id'], 404);
            }
            $message = match ($role) {
                'parent' => $messenger->sendParent($request->user(), $fields['student_id'], $fields['teacher_id'], $fields['body'], $fields['client_request_id']),
                'student' => $messenger->sendStudent($request->user(), $fields['recipient_id'], $fields['body'], $fields['client_request_id']),
                default => $messenger->sendReply($request->user(), $fields['conversation_id'], $fields['body'], $fields['client_request_id']),
            };
        } catch (ValidationException $error) {
            $thread = $role === 'parent' && $request->integer('teacher_id') > 0
                ? $messenger->teacherKey($request->integer('teacher_id'))
                : ($role === 'student' && $request->integer('recipient_id') > 0 ? $messenger->studentKey($request->user()->id, $request->integer('recipient_id')) : null);
            $parameters = $thread ? ['thread' => $thread] : [];
            if ($role === 'parent') {
                $selected = app(ParentMessageScope::class)->selected($request);
                if ($selected) {
                    $parameters['student'] = $selected->id;
                }
            }
            $error->redirectTo(route($role.'.messages', $parameters));
            throw $error;
        }

        $url = $messenger->conversationUrl($request->user(), $message->conversation);

        return redirect($url)->with('success', 'تم إرسال الرسالة.');
    }

    public function read(Request $request, PortalMessengerService $messenger): JsonResponse
    {
        $messenger->read($request);

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }
}
