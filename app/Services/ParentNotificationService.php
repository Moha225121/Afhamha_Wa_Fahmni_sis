<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ParentPortalNotification;

class ParentNotificationService
{
    public function __construct(private readonly WebPushService $webPush) {}

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function send(User $user, array $payload): void
    {
        $user->notify(new ParentPortalNotification($payload));
        $this->webPush->send($user, $payload);
    }

    public function sendAnnouncement(Announcement $announcement): void
    {
        app(ParentAnnouncementNotificationService::class)->sendAnnouncement($announcement);
    }

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function sendToGuardians(Student $student, array $payload): int
    {
        $parents = $student->guardians()->with('user')->get()
            ->map(fn ($guardian) => $guardian->user)
            ->filter(fn (?User $user) => $user?->isParent())
            ->unique('id')
            ->values();

        $parents->each(fn (User $parent) => $this->send($parent, $payload + ['student_id' => $student->id]));

        return $parents->count();
    }
}
