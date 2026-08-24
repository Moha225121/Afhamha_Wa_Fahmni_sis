<?php

namespace App\Http\Controllers\StudentPortal;

use App\Exceptions\SmartTutorGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TutorMessageRequest;
use App\Models\Student;
use App\Models\TutorConversation;
use App\Services\SmartTutorConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TutorController extends Controller
{
    public function index(Request $request): View
    {
        $student = $this->student($request);
        $conversations = $student->tutorConversations()
            ->withCount('messages')
            ->latest('updated_at')
            ->simplePaginate(max(1, (int) config('smart_tutor.display.conversations_per_page', 30)));

        return view('student.tutor.index', compact('student', 'conversations'));
    }

    public function storeConversation(Request $request): RedirectResponse
    {
        $student = $this->student($request);
        $conversation = $student->tutorConversations()->create(['title' => 'محادثة جديدة']);

        return redirect()->route('student.tutor.show', $conversation);
    }

    public function show(
        Request $request,
        int $conversation,
        SmartTutorConversationService $service,
    ): View {
        $student = $this->student($request);
        $conversation = $this->conversationFor($student, $conversation);
        $service->expireStalePending($conversation);
        $messages = $conversation->messages()
            ->where(function ($messages) use ($conversation): void {
                $messages
                    ->where('role', 'user')
                    ->orWhere(function ($answers) use ($conversation): void {
                        $answers
                            ->where('role', 'assistant')
                            ->where('delivery_status', 'answered')
                            ->whereNotNull('in_reply_to_message_id')
                            ->whereHas('replyTo', fn ($questions) => $questions
                                ->where('tutor_conversation_id', $conversation->id)
                                ->where('role', 'user')
                                ->where('delivery_status', 'answered'));
                    });
            })
            ->latest('id')
            ->simplePaginate(
                max(1, (int) config('smart_tutor.display.messages_per_page', 50)),
                ['*'],
                'messages',
            );
        $messages->setCollection($messages->getCollection()->reverse()->values());
        $conversations = $student->tutorConversations()
            ->whereKeyNot($conversation->id)
            ->latest('updated_at')
            ->limit(max(1, (int) config('smart_tutor.display.sidebar_conversations', 29)))
            ->get();
        $conversations->prepend($conversation);

        $messageRequestId = (string) Str::uuid();
        $messageMinLength = max(1, (int) config('smart_tutor.input.min_characters', 2));
        $messageMaxLength = max($messageMinLength, (int) config('smart_tutor.input.max_characters', 4000));

        return view('student.tutor.show', compact(
            'student',
            'conversation',
            'messages',
            'conversations',
            'messageRequestId',
            'messageMinLength',
            'messageMaxLength',
        ));
    }

    public function storeMessage(
        TutorMessageRequest $request,
        int $conversation,
        SmartTutorConversationService $service,
    ): RedirectResponse {
        $student = $this->student($request);
        $conversation = $this->conversationFor($student, $conversation);
        $validated = $request->validated();

        try {
            $service->send(
                $conversation,
                $validated['message'],
                $validated['request_id'],
                [
                    'classroom' => $student->classroom?->name,
                    'stage' => $student->classroom?->stage,
                ],
            );
        } catch (SmartTutorGatewayException $exception) {
            $redirect = redirect()
                ->route('student.tutor.show', $conversation)
                ->withErrors(['tutor' => $this->safeErrorMessage($exception)]);

            if ($exception->reason === SmartTutorGatewayException::REQUEST_IN_PROGRESS) {
                $redirect->withInput([
                    'message' => $validated['message'],
                    'request_id' => $validated['request_id'],
                ]);
            }

            return $redirect;
        }

        return redirect()->route('student.tutor.show', $conversation);
    }

    private function student(Request $request): Student
    {
        return $request->user()
            ->student()
            ->with(['user', 'classroom.academicYear'])
            ->firstOrFail();
    }

    private function conversationFor(Student $student, int $conversationId): TutorConversation
    {
        return $student->tutorConversations()->whereKey($conversationId)->firstOrFail();
    }

    private function safeErrorMessage(SmartTutorGatewayException $exception): string
    {
        return match ($exception->reason) {
            SmartTutorGatewayException::NOT_CONFIGURED => 'خدمة المعلّم الذكي غير مهيأة حاليًا. حُفظ سؤالك دون إرسال بيانات إلى مزود خارجي.',
            SmartTutorGatewayException::TIMEOUT => 'انتهت مهلة اتصال المعلّم الذكي. حُفظ سؤالك ولم تُحفظ إجابة فارغة.',
            SmartTutorGatewayException::RATE_LIMITED => 'مزود المعلّم الذكي مشغول حاليًا. حُفظ سؤالك، ويرجى المحاولة لاحقًا.',
            SmartTutorGatewayException::INVALID_RESPONSE => 'وصل رد غير صالح من المعلّم الذكي. حُفظ سؤالك ولم يُحفظ الرد.',
            SmartTutorGatewayException::REQUEST_CONFLICT => 'تعارض معرّف الطلب مع سؤال مختلف. لم يُرسل السؤال المكرر إلى المعلّم الذكي.',
            SmartTutorGatewayException::REQUEST_IN_PROGRESS => 'هذا السؤال قيد المعالجة بالفعل، ولم يُرسل مرة أخرى.',
            SmartTutorGatewayException::STALE_PENDING => 'تعذر إكمال سؤال سابق بعد انقطاع المعالجة. يمكنك إعادة إرساله الآن كطلب جديد.',
            default => 'تعذر الحصول على رد من المعلّم الذكي. حُفظ سؤالك بأمان.',
        };
    }
}
