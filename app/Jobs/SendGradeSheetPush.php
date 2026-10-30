<?php

namespace App\Jobs;

use App\Models\Student;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use RuntimeException;
use Throwable;

class SendGradeSheetPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $notificationId)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(PushNotificationService $push): void
    {
        try {
            $notification = DatabaseNotification::find($this->notificationId);
            if (! $notification || $notification->type !== ParentPortalNotification::class
                || $notification->notifiable_type !== (new User)->getMorphClass()) {
                return;
            }
            $data = $notification->data;
            $student = Student::where('status', 'active')->find($data['student_id'] ?? null);
            $user = User::find($notification->notifiable_id);
            if (! $student || ! $user || ($data['category'] ?? null) !== 'grade' || empty($data['grades'])) {
                return;
            }
            $linked = $user->isStudent() && (int) $student->user_id === (int) $user->id;
            if ($user->isParent()) {
                $linked = $student->guardians()->where('guardians.status', 'active')->where('user_id', $user->id)->exists();
            }
            if ($linked) {
                $push->send($user, $data + ['notification_id' => $this->notificationId]);
            }
        } catch (Throwable) {
            // Queue failure storage must not expose grades or transport credentials.
            throw new RuntimeException('Grade push delivery could not be completed.');
        }
    }
}
