<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\PortalMessengerService;
use App\Services\TeacherMessageReceiptService;
use App\Services\TeacherNotificationAudience;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

class SendTeacherNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $actorId, public string $dispatchId, public int $recipientId,
        public array $filters, public string $title, public string $message,
        public ?array $recipientContext = null,
    ) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public static function notificationId(string $dispatchId, int $recipientId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'school:teacher-notification:'.$dispatchId.':'.$recipientId)->toString();
    }

    public function handle(TeacherNotificationAudience $audience, ParentNotificationService $notifications): void
    {
        try {
            $recipient = $this->authorizedRecipient($audience);
            if (! $recipient) {
                return;
            }
            $notificationId = self::notificationId($this->dispatchId, $this->recipientId);
            $payload = $this->payload($recipient, $audience);
            $result = $audience->isMessaging($this->filters)
                ? $notifications->sendQueuedMessage($recipient, $payload, $notificationId)
                : $notifications->sendOnce($recipient, $payload, $notificationId);
            // A retried job must not overwrite a known push result with a duplicate attempt.
            if ($result['stored'] || ($result['push_attempted'] ?? false) || ! AuditLog::where('module', 'teacher_notification_delivery')
                ->where('record_id', $this->recipientId)->where('new_values->dispatch_id', $this->dispatchId)->exists()) {
                $this->record('stored', $result['fcm_accepted'] + $result['web_push_accepted'], $result['push_attempted'] ?? false);
            }
        } catch (Throwable) {
            // Queue failure storage must never include SDK/SQL exception text or message contents.
            throw new RuntimeException('Teacher notification delivery could not be completed.');
        }
    }

    /** The controller has already resolved and confirmed this exact recipient and child context. */
    public function storeForRecipient(User $recipient, TeacherNotificationAudience $audience, ParentNotificationService $notifications): bool
    {
        if (! $audience->isMessaging($this->filters) || (int) $recipient->id !== $this->recipientId) {
            return false;
        }

        return DB::transaction(function () use ($recipient, $audience, $notifications): bool {
            $stored = $notifications->storeForQueuedMessage($recipient, $this->inboxPayload($recipient, $audience),
                self::notificationId($this->dispatchId, $this->recipientId));
            if ($stored) {
                $this->record('stored');
            }

            return $stored;
        });
    }

    /** Inbox payloads need no device receipt URL or per-recipient database queries. */
    public function inboxPayload(User $recipient, TeacherNotificationAudience $audience): array
    {
        return $this->payload($recipient, $audience, false);
    }

    /** Recover only the local inbox copy; leave the original queued job to deliver push normally. */
    public function restoreInbox(TeacherNotificationAudience $audience, ParentNotificationService $notifications): bool
    {
        if (! $audience->isMessaging($this->filters)) {
            return false;
        }
        $recipient = $this->authorizedRecipient($audience);

        return $recipient ? $this->storeForRecipient($recipient, $audience, $notifications) : false;
    }

    private function authorizedRecipient(TeacherNotificationAudience $audience): ?User
    {
        $actor = User::find($this->actorId);
        $teacher = $actor?->teacher;
        if ($this->batch()?->cancelled() || ! $actor?->isTeacher() || $teacher?->status !== 'active') {
            $this->record('skipped');

            return null;
        }
        try {
            $recipient = $audience->query($teacher, $this->filters)->whereKey($this->recipientId)->first();
            $context = $recipient ? ($audience->contexts($teacher, $this->filters, collect([$recipient]))[$recipient->id] ?? null) : null;
        } catch (ValidationException) {
            $recipient = null;
            $context = null;
        }
        if (! $recipient || ($audience->isMessaging($this->filters) && ($context === null || $context !== ($this->recipientContext ?? null)))) {
            $this->record('skipped');

            return null;
        }

        return $recipient;
    }

    private function payload(User $recipient, TeacherNotificationAudience $audience, bool $forPush = true): array
    {
        $context = $this->recipientContext;
        $notificationId = self::notificationId($this->dispatchId, $this->recipientId);
        $payload = [
            'title' => $this->title, 'body' => $audience->messageBody($recipient, $this->message, $context), 'category' => 'teacher_notification',
            'url' => route($recipient->role === 'student' ? 'student.notifications' : 'parent.notifications', [], false),
            'student_id' => count($context['student_ids'] ?? []) === 1 ? $context['student_ids'][0] : null,
        ];
        if ($audience->isMessaging($this->filters)) {
            $payload['student_ids'] = array_values($context['student_ids']);
            $payload['teacher_message_id'] = $notificationId;
            if ($forPush) {
                $receipts = app(TeacherMessageReceiptService::class);
                $receipts->prepare($notificationId, $this->actorId, $this->recipientId, $this->dispatchId);
                $payload['receipt_url'] = $receipts->deliveryUrl($notificationId);
            }
            $parameters = ['thread' => app(PortalMessengerService::class)->teacherKey($this->actorId)];
            if ($recipient->role === 'parent') {
                $parameters['student'] = $context['student_ids'][0];
            }
            $payload['url'] = route($recipient->role.'.messages', $parameters, false);
        }

        return $payload;
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed');
    }

    private function record(string $state, int $accepted = 0, bool $pushAttempted = false): void
    {
        $stored = AuditLog::where('module', 'teacher_notification_delivery')->where('record_id', $this->recipientId)
            ->where('new_values->dispatch_id', $this->dispatchId)->where('action', 'stored')->first();
        if ($stored) {
            if ($pushAttempted) {
                $stored->update(['new_values' => array_replace($stored->new_values, ['push_accepted' => $accepted])]);
            }

            return;
        }
        AuditLog::create([
            'user_id' => User::whereKey($this->actorId)->exists() ? $this->actorId : null,
            'action' => $state, 'module' => 'teacher_notification_delivery', 'record_id' => $this->recipientId,
            'new_values' => ['dispatch_id' => $this->dispatchId, 'recipient_id' => $this->recipientId, 'push_accepted' => $accepted],
        ]);
    }
}
