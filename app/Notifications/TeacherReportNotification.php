<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TeacherReportNotification extends Notification
{
    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return array_intersect_key($this->payload, array_flip(['title', 'body', 'category', 'delivery_id', 'student_id', 'url']));
    }
}
