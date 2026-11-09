<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Exceptions\SmartTutorGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherTutorMessageRequest;
use App\Models\TutorConversation;
use App\Services\SmartTutorConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TutorController extends Controller
{
    public function index(Request $request): View
    {
        $conversations = $this->conversations($request)->withCount('messages')->latest('updated_at')
            ->simplePaginate(max(1, (int) config('smart_tutor.display.conversations_per_page', 30)));

        return view('teacher.tutor.index', [
            'conversations' => $conversations,
            'conversationRequestId' => (string) Str::uuid(),
        ]);
    }

    public function storeConversation(Request $request): RedirectResponse
    {
        if (array_diff(array_keys($request->all()), ['_token', 'request_id']) !== []) {
            throw ValidationException::withMessages(['payload' => 'يحتوي الطلب على حقول غير مسموح بها.']);
        }
        $validated = $request->validate(['request_id' => ['required', 'uuid']]);
        $conversation = TutorConversation::query()->createOrFirst([
            'teacher_user_id' => $request->user()->id,
            'creation_request_id' => strtolower($validated['request_id']),
        ], ['student_id' => null, 'title' => 'محادثة جديدة']);
        abort_unless($conversation->student_id === null, 404);

        return redirect()->route('teacher.tutor.show', $conversation);
    }

    public function show(Request $request, int $conversation, SmartTutorConversationService $service): View
    {
        $conversation = $this->conversations($request)->whereKey($conversation)->firstOrFail();
        $service->expireStalePending($conversation);
        $messages = $conversation->messages()
            ->where(fn ($messages) => $messages->where('role', 'user')->orWhere(fn ($answers) => $answers
                ->where('role', 'assistant')->where('delivery_status', 'answered')->whereNotNull('in_reply_to_message_id')
                ->whereHas('replyTo', fn ($questions) => $questions->where('tutor_conversation_id', $conversation->id)
                    ->where('role', 'user')->where('delivery_status', 'answered'))))
            ->latest('id')->simplePaginate(max(1, (int) config('smart_tutor.display.messages_per_page', 50)), ['*'], 'messages');
        $messages->setCollection($messages->getCollection()->reverse()->values());
        $conversations = $this->conversations($request)->whereKeyNot($conversation->id)->latest('updated_at')
            ->limit(max(1, (int) config('smart_tutor.display.sidebar_conversations', 29)))->get();
        $conversations->prepend($conversation);
        $minimum = max(1, (int) config('smart_tutor.input.min_characters', 2));

        return view('teacher.tutor.show', [
            'conversation' => $conversation, 'conversations' => $conversations, 'messages' => $messages,
            'conversationRequestId' => (string) Str::uuid(), 'messageRequestId' => (string) Str::uuid(),
            'messageMinLength' => $minimum,
            'messageMaxLength' => max($minimum, (int) config('smart_tutor.input.max_characters', 4000)),
        ]);
    }

    public function storeMessage(TeacherTutorMessageRequest $request, int $conversation, SmartTutorConversationService $service): RedirectResponse
    {
        $conversation = $this->conversations($request)->whereKey($conversation)->firstOrFail();
        $validated = $request->validated();
        try {
            // No student profile, grades, roster or other person's history is loaded.
            $service->send($conversation, $validated['message'], $validated['request_id']);
        } catch (SmartTutorGatewayException $exception) {
            $message = match ($exception->reason) {
                SmartTutorGatewayException::NOT_CONFIGURED => 'خدمة المساعد التعليمي غير مهيأة حاليًا. حُفظ سؤالك دون إرسال بيانات إلى مزود خارجي.',
                SmartTutorGatewayException::TIMEOUT => 'انتهت مهلة اتصال المساعد التعليمي. حُفظ سؤالك ولم تُحفظ إجابة فارغة.',
                SmartTutorGatewayException::RATE_LIMITED => 'المساعد التعليمي مشغول حاليًا. حُفظ سؤالك، ويرجى المحاولة لاحقًا.',
                SmartTutorGatewayException::INVALID_RESPONSE => 'وصل رد غير صالح من المساعد التعليمي. حُفظ سؤالك ولم يُحفظ الرد.',
                SmartTutorGatewayException::REQUEST_CONFLICT => 'تعارض معرّف الطلب مع سؤال مختلف. لم يُرسل السؤال المكرر.',
                SmartTutorGatewayException::REQUEST_IN_PROGRESS => 'هذا السؤال قيد المعالجة بالفعل، ولم يُرسل مرة أخرى.',
                SmartTutorGatewayException::STALE_PENDING => 'تعذر إكمال سؤال سابق بعد انقطاع المعالجة. يمكنك إعادة إرساله الآن كطلب جديد.',
                default => 'تعذر الحصول على رد من المساعد التعليمي. حُفظ سؤالك بأمان.',
            };
            $redirect = redirect()->route('teacher.tutor.show', $conversation)->withErrors(['tutor' => $message]);
            if ($exception->reason === SmartTutorGatewayException::REQUEST_IN_PROGRESS) {
                $redirect->withInput($validated);
            }

            return $redirect;
        }

        return redirect()->route('teacher.tutor.show', $conversation);
    }

    private function conversations(Request $request): Builder
    {
        return TutorConversation::query()->where('teacher_user_id', $request->user()->id)->whereNull('student_id');
    }
}
