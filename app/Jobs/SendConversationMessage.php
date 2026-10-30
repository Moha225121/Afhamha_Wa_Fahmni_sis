<?php

namespace App\Jobs;

use App\Models\ConversationMessageReceipt;
use App\Models\Message;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\PortalMessengerService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SendConversationMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $messageId, public int $recipientId, public string $notificationId) {}

    public function handle(PortalMessengerService $messenger, ParentNotificationService $notifications): void
    {
        try {
            $this->deliver($messenger, $notifications);
        } catch (Throwable) {
            // Failed-job diagnostics must not include SQL bindings, private bodies, or provider details.
            throw new RuntimeException('Conversation message delivery could not be completed.');
        }
    }

    private function deliver(PortalMessengerService $messenger, ParentNotificationService $notifications): void
    {
        if (! $messenger->available()) {
            return;
        }
        $message = Message::with(['sender', 'conversation.student.user'])->find($this->messageId);
        $recipient = User::find($this->recipientId);
        $receipt = ConversationMessageReceipt::whereKey($this->notificationId)->where('message_id', $this->messageId)->where('recipient_user_id', $this->recipientId)->first();
        if (! $receipt || ! $message || ! $recipient || (int) $message->sender_id === (int) $recipient->id) {
            return;
        }
        $conversation = $messenger->authorizedConversation($recipient, $message->conversation_id);
        if (! $conversation || ! $conversation->participants->contains('id', $message->sender_id)) {
            return;
        }
        $url = $messenger->conversationUrl($recipient, $conversation);
        if (! $url) {
            return;
        }
        $notifications->sendOnce($recipient, [
            'title' => $message->sender?->name ?? 'رسالة جديدة',
            'body' => 'بخصوص: '.($conversation->student?->user?->name ?? '')."\n\n".$message->body,
            'url' => $url, 'category' => 'conversation_message', 'conversation_id' => $conversation->id,
            'student_id' => $conversation->student_id, 'receipt_url' => $messenger->deliveryUrl($this->notificationId),
        ], $this->notificationId);
        // A recipient can read the in-app message before the queue worker stores its alert.
        if ($receipt->fresh()?->viewed_at) {
            $recipient->notifications()->whereKey($this->notificationId)->whereNull('read_at')->update(['read_at' => now()]);
        }
    }
}
