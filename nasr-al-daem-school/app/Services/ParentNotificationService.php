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
        if ($announcement->status !== 'published' || ($announcement->published_at && $announcement->published_at->isFuture())) {
            return;
        }

        $parents = User::query()
            ->where('role', 'parent')
            ->where('status', 'active')
            ->whereHas('guardian', function ($query) use ($announcement): void {
                if ($announcement->classroom_id) {
                    $query->whereHas('students', fn ($students) => $students->where('classroom_id', $announcement->classroom_id));
                }
            })
            ->get();

        $payload = [
            'title' => $announcement->title,
            'body' => (string) str($announcement->content)->limit(140),
            'url' => route('parent.notifications'),
            'category' => 'announcement',
        ];

        $parents->each(fn (User $parent) => $this->send($parent, $payload));
    }

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function sendToGuardians(Student $student, array $payload): void
    {
        $student->guardians()->with('user')->get()
            ->map(fn ($guardian) => $guardian->user)
            ->filter(fn (?User $user) => $user?->isParent())
            ->each(fn (User $parent) => $this->send($parent, $payload + ['student_id' => $student->id]));
    }
}
