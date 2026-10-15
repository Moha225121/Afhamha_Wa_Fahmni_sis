<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Student;
use App\Models\User;
use App\Notifications\StudentAnnouncementNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class StudentNotificationService
{
    public function sendAnnouncement(Announcement $announcement): void
    {
        if (! Announcement::currentlyPublished()->whereKey($announcement->id)->exists()
            || ! in_array($announcement->audience, ['all', 'students', 'classroom'], true)
            || ($announcement->audience === 'classroom' && ! $announcement->classroom_id)) {
            return;
        }

        Student::query()->where('status', 'active')
            ->whereHas('user', fn (Builder $users) => $users->where('role', 'student')->where('status', 'active'))
            ->when($announcement->audience === 'classroom', fn (Builder $students) => $students->where('classroom_id', $announcement->classroom_id))
            ->with('user')->chunkById(200, function ($students) use ($announcement): void {
                foreach ($students as $student) {
                    $this->storeAnnouncement($student->user, $announcement);
                }
            });
    }

    /**
     * Sync on access also delivers scheduled announcements when their publication time arrives.
     * Old records retain their read state, but withdrawn or retargeted announcements are hidden.
     */
    public function queryFor(Student $student): MorphMany
    {
        $announcements = Announcement::visibleToStudent($student)->get();
        $existing = DB::table('notifications')->where('notifiable_type', $student->user->getMorphClass())
            ->where('notifiable_id', $student->user_id)->where('type', StudentAnnouncementNotification::class)
            ->pluck('data', 'id')->all();
        $ids = [];
        foreach ($announcements as $announcement) {
            $ids[] = $this->storeAnnouncement($student->user, $announcement, $existing);
        }

        return $student->user->notifications()->where(function (Builder $query) use ($ids): void {
            $query->where('type', '!=', StudentAnnouncementNotification::class)
                ->orWhereIn('id', $ids);
        });
    }

    public function summary(Student $student, bool $unreadOnly = false): array
    {
        $query = $this->queryFor($student);

        return [
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'notifications' => (clone $query)->when($unreadOnly, fn ($notifications) => $notifications->whereNull('read_at'))
                ->latest()->orderByDesc('id')->limit(5)->get()->map(fn (DatabaseNotification $notification) => $this->serialize($notification))->all(),
            'all_url' => route('student.notifications'),
            'read_all_url' => route('student.notifications.read-all'),
        ];
    }

    public function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? 'إشعار جديد',
            'body' => Str::limit(strip_tags($notification->data['body'] ?? $notification->data['message'] ?? ''), 140),
            'category' => $notification->data['category'] ?? 'general',
            'url' => $this->destination($notification),
            'read_url' => route('student.notifications.read', $notification->id),
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'time_label' => $notification->created_at?->locale('ar')->diffForHumans(),
        ];
    }

    public function destination(DatabaseNotification $notification): string
    {
        if ($notification->type === StudentAnnouncementNotification::class) {
            return route('student.announcements.show', $notification->data['announcement_id']);
        }

        $url = $notification->data['url'] ?? '';
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        if (is_string($path) && str_starts_with($path, '/student/')) {
            return $path;
        }

        return route('student.notifications');
    }

    public function markAnnouncementRead(Student $student, Announcement $announcement): void
    {
        $id = $this->storeAnnouncement($student->user, $announcement);
        $student->user->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function storeAnnouncement(User $user, Announcement $announcement, ?array $existingById = null): string
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:student-announcement:'.$announcement->id.':'.$user->id)->toString();
        $data = json_encode([
            'title' => $announcement->title,
            'body' => Str::limit(strip_tags($announcement->content), 220),
            'category' => 'announcement',
            'announcement_id' => $announcement->id,
            'url' => route('student.announcements.show', $announcement->id, false),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $existingData = $existingById === null
            ? DB::table('notifications')->where('id', $id)->value('data')
            : ($existingById[$id] ?? null);
        if ($existingData === null) {
            // The deterministic primary key also makes overlapping polls/publications safe.
            DB::table('notifications')->insertOrIgnore([
                'id' => $id,
                'type' => StudentAnnouncementNotification::class,
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => $data,
                'read_at' => null,
                'created_at' => $announcement->published_at ?? $announcement->updated_at ?? now(),
                'updated_at' => now(),
            ]);
        } elseif ($existingData !== $data) {
            DB::table('notifications')->where('id', $id)->update(['data' => $data, 'updated_at' => now()]);
        }

        return $id;
    }
}
