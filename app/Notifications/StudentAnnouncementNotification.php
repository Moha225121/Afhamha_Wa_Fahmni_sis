<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StudentAnnouncementNotification extends Notification
{
    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
