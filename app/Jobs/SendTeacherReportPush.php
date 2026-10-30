<?php

namespace App\Jobs;

use App\Models\TeacherReportDelivery;
use App\Models\User;
use App\Notifications\TeacherReportNotification;
use App\Services\PushNotificationService;
use App\Services\TeacherReportDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTeacherReportPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $deliveryId)
    {
        $this->onConnection('database')->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(PushNotificationService $push, TeacherReportDeliveryService $reports): void
    {
        if (! $reports->ready()) {
            return;
        }
        $notification = DatabaseNotification::find($this->deliveryId);
        if (! $notification || $notification->type !== TeacherReportNotification::class
            || $notification->notifiable_type !== (new User)->getMorphClass()) {
            return;
        }
        $user = User::find($notification->notifiable_id);
        if (! $user || ! $reports->canReceive($user, $this->deliveryId)) {
            return;
        }
        $claimed = DB::transaction(fn () => TeacherReportDelivery::whereKey($this->deliveryId)
            ->whereNull('push_attempted_at')->update(['push_attempted_at' => now()]));
        if (! $claimed) {
            return;
        }
        try {
            $push->send($user, $notification->data + ['notification_id' => $this->deliveryId]);
        } catch (Throwable) {
            // The committed inbox remains available even when a device provider is unavailable.
            Log::warning('Report device notification could not be sent.', ['delivery_id' => $this->deliveryId]);
        }
    }
}
