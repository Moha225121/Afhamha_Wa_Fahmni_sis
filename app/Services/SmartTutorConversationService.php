<?php

namespace App\Services;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorTurn;
use App\Exceptions\SmartTutorGatewayException;
use App\Models\TutorConversation;
use App\Models\TutorMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SmartTutorConversationService
{
    private const SYSTEM_INSTRUCTION = 'أنت معلّم مساعد للطالب. قدّم شرحًا تعليميًا عامًا وواضحًا باللغة العربية، ولا تطلب أو تكشف كلمات مرور أو أسرارًا أو إعدادات خادم أو بيانات تخص مستخدمين آخرين. لا تدّع الوصول إلى معلومات غير موجودة في السياق.';

    public function __construct(private readonly SmartTutorGateway $gateway) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function send(
        TutorConversation $conversation,
        string $content,
        string $clientRequestId,
        array $context = [],
    ): TutorMessage {
        // The conversation-scoped unique key is the final defence against concurrent duplicate requests.
        $userMessage = $conversation->messages()->createOrFirst(
            ['client_request_id' => $clientRequestId],
            [
                'role' => 'user',
                'content' => $content,
                'delivery_status' => 'pending',
            ],
        );

        if (! $userMessage->wasRecentlyCreated) {
            return $this->replayedResult($userMessage, $content);
        }

        if ($conversation->title === 'محادثة جديدة') {
            TutorConversation::query()
                ->whereKey($conversation->id)
                ->where('title', 'محادثة جديدة')
                ->update(['title' => Str::limit($content, 60)]);
        }

        try {
            // The external call deliberately stays outside a database transaction.
            $reply = $this->gateway->reply($this->promptFor($conversation, $userMessage, $context));
            $replyContent = $this->validatedReplyContent($reply->content);
        } catch (SmartTutorGatewayException $exception) {
            if (
                ! $this->markFailed($conversation, $userMessage, $exception)
                && $completedReply = $this->resolvedReplyAfterFailure($userMessage)
            ) {
                return $completedReply;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $gatewayException = SmartTutorGatewayException::upstreamFailure($exception);

            if (
                ! $this->markFailed($conversation, $userMessage, $gatewayException, $exception::class)
                && $completedReply = $this->resolvedReplyAfterFailure($userMessage)
            ) {
                return $completedReply;
            }

            throw $gatewayException;
        }

        try {
            return DB::transaction(function () use ($conversation, $userMessage, $content, $replyContent): TutorMessage {
                $lockedQuestion = TutorMessage::query()
                    ->whereKey($userMessage->id)
                    ->where('tutor_conversation_id', $conversation->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedQuestion || $lockedQuestion->role !== 'user') {
                    throw SmartTutorGatewayException::upstreamFailure();
                }

                if ($lockedQuestion->delivery_status !== 'pending') {
                    return $this->replayedResult($lockedQuestion, $content);
                }

                $assistantMessage = $conversation->messages()->createOrFirst(
                    ['in_reply_to_message_id' => $lockedQuestion->id],
                    [
                        'role' => 'assistant',
                        'content' => $replyContent,
                        'delivery_status' => 'answered',
                    ],
                );

                if (
                    $assistantMessage->tutor_conversation_id !== $conversation->id
                    || $assistantMessage->role !== 'assistant'
                    || $assistantMessage->delivery_status !== 'answered'
                ) {
                    throw SmartTutorGatewayException::upstreamFailure();
                }

                $this->validatedReplyContent($assistantMessage->content);

                $answered = TutorMessage::query()
                    ->whereKey($lockedQuestion->id)
                    ->where('tutor_conversation_id', $conversation->id)
                    ->where('role', 'user')
                    ->where('delivery_status', 'pending')
                    ->update([
                        'delivery_status' => 'answered',
                        'failure_reason' => null,
                    ]);

                if ($answered !== 1) {
                    $currentQuestion = $lockedQuestion->fresh();

                    if (! $currentQuestion) {
                        throw SmartTutorGatewayException::upstreamFailure();
                    }

                    return $this->replayedResult($currentQuestion, $content);
                }

                return $assistantMessage;
            });
        } catch (SmartTutorGatewayException $exception) {
            if (
                ! $this->markFailed($conversation, $userMessage, $exception)
                && $completedReply = $this->resolvedReplyAfterFailure($userMessage)
            ) {
                return $completedReply;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $gatewayException = SmartTutorGatewayException::upstreamFailure($exception);

            if (
                ! $this->markFailed($conversation, $userMessage, $gatewayException, $exception::class)
                && $completedReply = $this->resolvedReplyAfterFailure($userMessage)
            ) {
                return $completedReply;
            }

            throw $gatewayException;
        }
    }

    /**
     * Recover questions left pending by a terminated PHP process without calling the gateway again.
     */
    public function expireStalePending(TutorConversation $conversation): int
    {
        return TutorMessage::query()
            ->where('tutor_conversation_id', $conversation->id)
            ->where('role', 'user')
            ->where('delivery_status', 'pending')
            ->where('created_at', '<=', $this->stalePendingCutoff())
            ->update([
                'delivery_status' => 'failed',
                'failure_reason' => SmartTutorGatewayException::STALE_PENDING,
            ]);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function promptFor(
        TutorConversation $conversation,
        TutorMessage $currentQuestion,
        array $context,
    ): SmartTutorPrompt {
        $maxMessages = max(1, (int) config('smart_tutor.history.max_messages', 20));
        $maxCharacters = max(
            mb_strlen($currentQuestion->content),
            (int) config('smart_tutor.history.max_characters', 16000),
        );
        $pairLimit = intdiv(max(0, $maxMessages - 1), 2);

        // Only the newest complete, answered pairs are eligible for the bounded gateway context.
        $answers = $conversation->messages()
            ->where('role', 'assistant')
            ->where('delivery_status', 'answered')
            ->whereNotNull('in_reply_to_message_id')
            ->whereHas('replyTo', fn ($query) => $query
                ->where('tutor_conversation_id', $conversation->id)
                ->where('role', 'user')
                ->where('delivery_status', 'answered')
                ->where('id', '<', $currentQuestion->id))
            ->with('replyTo')
            ->orderByDesc('in_reply_to_message_id')
            ->limit($pairLimit)
            ->get();

        $usedCharacters = mb_strlen($currentQuestion->content);
        $pairs = [];

        foreach ($answers as $answer) {
            $question = $answer->replyTo;

            if (
                ! $question
                || $question->tutor_conversation_id !== $conversation->id
                || ! $this->contentIsValid(
                    $question->content,
                    max(1, (int) config('smart_tutor.input.min_characters', 2)),
                    max(1, (int) config('smart_tutor.input.max_characters', 4000)),
                )
                || ! $this->contentIsValid(
                    $answer->content,
                    1,
                    max(1, (int) config('smart_tutor.reply.max_characters', 12000)),
                )
            ) {
                continue;
            }

            $pairCharacters = mb_strlen($question->content) + mb_strlen($answer->content);

            if ($usedCharacters + $pairCharacters > $maxCharacters) {
                break;
            }

            $pairs[] = [$question, $answer];
            $usedCharacters += $pairCharacters;
        }

        $turns = [new SmartTutorTurn('system', self::SYSTEM_INSTRUCTION)];

        foreach (array_reverse($pairs) as [$question, $answer]) {
            $turns[] = new SmartTutorTurn('user', $question->content);
            $turns[] = new SmartTutorTurn('assistant', $answer->content);
        }

        $turns[] = new SmartTutorTurn('user', $currentQuestion->content);

        return new SmartTutorPrompt(
            $turns,
            'ar',
            array_intersect_key($context, array_flip(['classroom', 'stage'])),
        );
    }

    private function validatedReplyContent(string $content): string
    {
        $maxCharacters = max(1, (int) config('smart_tutor.reply.max_characters', 12000));

        if (! $this->contentIsValid($content, 1, $maxCharacters)) {
            throw SmartTutorGatewayException::invalidResponse();
        }

        return trim($content);
    }

    private function contentIsValid(string $content, int $minimum, int $maximum): bool
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            return false;
        }

        $content = trim($content);
        $visibleContent = preg_replace('/[\pZ\pC]+/u', '', $content);

        return $content !== ''
            && $visibleContent !== null
            && mb_strlen($visibleContent) >= $minimum
            && preg_match('/\S/u', $content) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) !== 1
            && mb_strlen($content) <= $maximum;
    }

    private function replayedResult(TutorMessage $userMessage, string $content): TutorMessage
    {
        if ($userMessage->role !== 'user' || ! hash_equals($userMessage->content, $content)) {
            throw SmartTutorGatewayException::fromReason(SmartTutorGatewayException::REQUEST_CONFLICT);
        }

        if ($userMessage->delivery_status === 'answered') {
            if ($reply = $this->completedReply($userMessage)) {
                return $reply;
            }

            throw SmartTutorGatewayException::upstreamFailure();
        }

        if ($userMessage->delivery_status === 'failed') {
            throw SmartTutorGatewayException::fromReason(
                $userMessage->failure_reason ?: SmartTutorGatewayException::UPSTREAM_FAILURE,
            );
        }

        if (
            $userMessage->delivery_status === 'pending'
            && $userMessage->created_at?->lte($this->stalePendingCutoff())
        ) {
            $exception = SmartTutorGatewayException::fromReason(SmartTutorGatewayException::STALE_PENDING);

            if ($this->markFailed($userMessage->conversation, $userMessage, $exception)) {
                throw $exception;
            }

            $freshMessage = $userMessage->fresh();

            if (! $freshMessage) {
                throw SmartTutorGatewayException::upstreamFailure();
            }

            return $this->replayedResult($freshMessage, $content);
        }

        throw SmartTutorGatewayException::fromReason(SmartTutorGatewayException::REQUEST_IN_PROGRESS);
    }

    private function completedReply(TutorMessage $userMessage): ?TutorMessage
    {
        $reply = $userMessage->reply()
            ->where('tutor_conversation_id', $userMessage->tutor_conversation_id)
            ->where('role', 'assistant')
            ->where('delivery_status', 'answered')
            ->first();

        if ($reply) {
            $this->validatedReplyContent($reply->content);
        }

        return $reply;
    }

    private function resolvedReplyAfterFailure(TutorMessage $userMessage): ?TutorMessage
    {
        $question = $userMessage->fresh();

        if (! $question || $question->role !== 'user' || $question->delivery_status !== 'answered') {
            return null;
        }

        return $this->completedReply($question);
    }

    private function stalePendingCutoff(): CarbonInterface
    {
        $pendingTimeout = max(
            1,
            (int) config('smart_tutor.idempotency.pending_timeout_seconds', 120),
        );

        return now()->subSeconds($pendingTimeout);
    }

    private function markFailed(
        TutorConversation $conversation,
        TutorMessage $userMessage,
        SmartTutorGatewayException $exception,
        ?string $unexpectedExceptionClass = null,
    ): bool {
        $logContext = [
            'conversation_id' => $conversation->id,
            'student_id' => $conversation->student_id,
            'reason' => $exception->reason,
        ];
        $transitioned = false;

        try {
            $transitioned = DB::transaction(function () use ($conversation, $userMessage, $exception): bool {
                $question = TutorMessage::query()
                    ->whereKey($userMessage->id)
                    ->where('tutor_conversation_id', $conversation->id)
                    ->where('role', 'user')
                    ->where('delivery_status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (! $question) {
                    return false;
                }

                $linkedReply = TutorMessage::query()
                    ->where('tutor_conversation_id', $conversation->id)
                    ->where('in_reply_to_message_id', $question->id)
                    ->where('role', 'assistant')
                    ->first();

                if (
                    $linkedReply
                    && $linkedReply->delivery_status === 'answered'
                    && $this->contentIsValid(
                        $linkedReply->content,
                        1,
                        max(1, (int) config('smart_tutor.reply.max_characters', 12000)),
                    )
                ) {
                    $answered = TutorMessage::query()
                        ->whereKey($question->id)
                        ->where('delivery_status', 'pending')
                        ->update([
                            'delivery_status' => 'answered',
                            'failure_reason' => null,
                        ]) === 1;

                    if (! $answered) {
                        $currentStatus = TutorMessage::query()->whereKey($question->id)->value('delivery_status');

                        if ($currentStatus === 'failed') {
                            TutorMessage::query()->whereKey($linkedReply->id)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => SmartTutorGatewayException::UPSTREAM_FAILURE,
                            ]);
                        } elseif ($currentStatus !== 'answered') {
                            throw new RuntimeException('Smart Tutor question state changed concurrently.');
                        }
                    }

                    return false;
                }

                $failureReason = $linkedReply
                    ? SmartTutorGatewayException::INVALID_RESPONSE
                    : $exception->reason;

                // Compare-and-set prevents a late failure from overwriting a completed answer.
                $questionFailed = TutorMessage::query()
                    ->whereKey($question->id)
                    ->where('delivery_status', 'pending')
                    ->update([
                        'delivery_status' => 'failed',
                        'failure_reason' => $failureReason,
                    ]) === 1;

                if (! $questionFailed) {
                    return false;
                }

                if ($linkedReply) {
                    $replyFailed = TutorMessage::query()
                        ->whereKey($linkedReply->id)
                        ->where('tutor_conversation_id', $conversation->id)
                        ->where('role', 'assistant')
                        ->update([
                            'delivery_status' => 'failed',
                            'failure_reason' => SmartTutorGatewayException::INVALID_RESPONSE,
                        ]) === 1;

                    if (! $replyFailed) {
                        throw new RuntimeException('Smart Tutor invalid reply state changed concurrently.');
                    }
                }

                return true;
            });
        } catch (Throwable $persistenceException) {
            $logContext['persistence_exception_class'] = $persistenceException::class;
        }

        $logContext['state_transitioned'] = $transitioned;

        if ($unexpectedExceptionClass !== null) {
            $logContext['exception_class'] = $unexpectedExceptionClass;
        }

        Log::warning('Smart Tutor request failed.', $logContext);

        return $transitioned;
    }
}
