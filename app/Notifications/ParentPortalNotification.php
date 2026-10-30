<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ParentPortalNotification extends Notification
{
    use Queueable;

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->payload['title'],
            'body' => $this->payload['body'],
            'url' => $this->payload['url'] ?? null,
            'category' => $this->payload['category'] ?? 'general',
            'student_id' => $this->payload['student_id'] ?? null,
        ] + array_intersect_key($this->payload, array_flip(['lesson_id', 'assignment_id', 'exam_id', 'announcement_id', 'conversation_id', 'exam_period_id']))
            + (($this->payload['category'] ?? null) === 'teacher_notification'
                ? array_intersect_key($this->payload, ['teacher_message_id' => true, 'student_ids' => true]) : []);
    }
}
