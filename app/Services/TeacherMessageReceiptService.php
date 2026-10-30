<?php

namespace App\Services;

use App\Models\TeacherMessageReceipt;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherMessageReceiptService
{
    private ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('teacher_message_receipts');
    }

    public function requireAvailable(): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['send' => 'يتطلب تحديث الرسائل إكمال التهيئة. تواصل مع مسؤول النظام.']);
        }
    }

    public function prepare(string $notificationId, int $teacherUserId, int $recipientUserId, string $dispatchId): TeacherMessageReceipt
    {
        $this->requireAvailable();

        return TeacherMessageReceipt::firstOrCreate(['notification_id' => $notificationId], [
            'teacher_user_id' => $teacherUserId, 'recipient_user_id' => $recipientUserId, 'dispatch_id' => $dispatchId,
        ]);
    }

    public function deliveryUrl(string $notificationId): ?string
    {
        if (! Str::isUuid($notificationId) || ! $this->available() || ! TeacherMessageReceipt::whereKey($notificationId)->exists()) {
            return null;
        }

        return URL::temporarySignedRoute('teacher-message-receipts.delivered', now()->addDays(2), ['notification' => $notificationId], false);
    }

    public function ownedNotification(User $recipient, string $notificationId): ?DatabaseNotification
    {
        return $recipient->notifications()->whereKey($notificationId)->where('data->category', 'teacher_notification')->first();
    }

    public function delivered(TeacherMessageReceipt $receipt): void
    {
        TeacherMessageReceipt::whereKey($receipt->notification_id)->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }

    public function viewed(TeacherMessageReceipt $receipt, DatabaseNotification $notification): void
    {
        DB::transaction(function () use ($receipt, $notification): void {
            $this->delivered($receipt);
            TeacherMessageReceipt::whereKey($receipt->notification_id)->whereNull('viewed_at')
                ->update(['viewed_at' => now(), 'updated_at' => now()]);
            if ($notification->read_at === null) {
                $notification->markAsRead();
            }
        });
    }
}
