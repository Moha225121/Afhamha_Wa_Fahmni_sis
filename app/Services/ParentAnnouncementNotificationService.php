<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\ParentAnnouncementNotification;
use App\Notifications\ParentPortalNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ParentAnnouncementNotificationService
{
    public function sendAnnouncement(Announcement $announcement): void
    {
        if (! Announcement::currentlyPublished()->whereKey($announcement->id)->exists()
            || ! in_array($announcement->audience, ['all', 'parent', 'parents', 'classroom'], true)
            || ($announcement->audience === 'classroom' && ! $announcement->classroom_id)) {
            return;
        }

        User::where('role', 'parent')->where('status', 'active')
            ->whereHas('guardian', function (Builder $guardians) use ($announcement): void {
                $guardians->where('status', 'active');
                if ($announcement->classroom_id) {
                    $guardians->whereHas('students', fn (Builder $students) => $students->where('classroom_id', $announcement->classroom_id));
                }
            })->chunkById(200, function ($parents) use ($announcement): void {
                foreach ($parents as $parent) {
                    $this->storeAnnouncement($parent, $announcement, true);
                }
            });
    }

    // Sync on every parent entry also recovers previously missed and scheduled ads.
    public function queryFor(User $parent): MorphMany
    {
        $followupIds = app(ParentFollowupNotificationService::class)->syncFor($parent);
        $guardian = $parent->guardian;
        $announcements = $guardian ? Announcement::visibleToParent($guardian)->get() : collect();
        $existing = $parent->notifications()->where('type', ParentAnnouncementNotification::class)->toBase()->pluck('data', 'id')->all();
        $ids = [];
        foreach ($announcements as $announcement) {
            $ids[] = $this->storeAnnouncement($parent, $announcement, false, $existing);
        }

        $studentIds = $guardian?->students()->pluck('students.id') ?? collect();
        $withdrawn = DB::table('exam_periods as e')->join('result_publications as p', 'p.exam_period_id', '=', 'e.id')
            ->whereIn('p.student_id', $studentIds)
            ->where(fn ($query) => $query->where('e.status', '!=', 'published')->orWhereNull('e.published_at')->orWhere('e.published_at', '>', now()))
            ->get(['e.id', 'e.revision']);
        $withdrawnIds = [];
        foreach ($withdrawn as $period) {
            for ($revision = 1; $revision <= $period->revision; $revision++) {
                $withdrawnIds[] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:results:'.$period->id.':'.$revision.':'.$parent->id)->toString();
            }
        }

        return $parent->notifications()->whereNotIn('id', $withdrawnIds)
            ->where(fn (Builder $personal) => $personal->whereNull('data->student_id')->orWhereIn('data->student_id', $studentIds))
            ->where(fn (Builder $followup) => $followup->whereNull('data->category')
                ->orWhereNotIn('data->category', ['student_note', 'guardian_call'])->orWhereIn('id', $followupIds))
            ->where(function (Builder $query) use ($ids): void {
                // Old generic announcement rows may be duplicates or mistargeted.
                $query->where(fn (Builder $personal) => $personal->where('type', '!=', ParentAnnouncementNotification::class)
                    ->where(fn (Builder $legacy) => $legacy->where('type', '!=', ParentPortalNotification::class)
                        ->orWhereNull('data->category')->orWhere('data->category', '!=', 'announcement')))
                    ->orWhereIn('id', $ids);
            });
    }

    public function summary(User $parent, bool $unreadOnly = false): array
    {
        $query = $this->queryFor($parent);

        return [
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'unread_by_category' => [
                'student_note' => (clone $query)->where('data->category', 'student_note')->whereNull('read_at')->count(),
                'guardian_call' => (clone $query)->where('data->category', 'guardian_call')->whereNull('read_at')->count(),
            ],
            'notifications' => (clone $query)->when($unreadOnly, fn ($items) => $items->whereNull('read_at'))
                ->latest()->orderByDesc('id')->limit(5)->get()->map(fn (DatabaseNotification $item) => $this->serialize($item))->all(),
            'all_url' => route('parent.notifications'),
            'read_all_url' => route('parent.notifications.read-all'),
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
            'read_url' => route('parent.notifications.read', $notification->id),
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'time_label' => $notification->created_at?->locale('ar')->diffForHumans(),
        ];
    }

    public function destination(DatabaseNotification $notification): string
    {
        if ($notification->type === ParentAnnouncementNotification::class) {
            return route('parent.announcements.show', $notification->data['announcement_id']);
        }
        $url = $notification->data['url'] ?? '';
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        if (! is_string($path) || ! str_starts_with($path, '/parent/')) {
            return route('parent.notifications');
        }
        $query = parse_url($url, PHP_URL_QUERY);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        return $path.($query !== null ? '?'.$query : '').($fragment !== null ? '#'.$fragment : '');
    }

    public function markAnnouncementRead(User $parent, Announcement $announcement): void
    {
        $parent->notifications()->whereKey($this->storeAnnouncement($parent, $announcement))->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function storeAnnouncement(User $parent, Announcement $announcement, bool $push = false, ?array $existingById = null): string
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:parent-announcement:'.$announcement->id.':'.$parent->id)->toString();
        $payload = [
            'title' => $announcement->title,
            'body' => Str::limit(strip_tags($announcement->content), 220),
            'category' => 'announcement',
            'announcement_id' => $announcement->id,
            'url' => route('parent.announcements.show', $announcement, false),
        ];
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $existing = $existingById === null ? DB::table('notifications')->where('id', $id)->value('data') : ($existingById[$id] ?? null);
        if ($existing === null) {
            $legacy = $parent->notifications()->where('type', ParentPortalNotification::class)
                ->where('data->category', 'announcement')->where('data->title', $announcement->title)
                ->get()->first(fn (DatabaseNotification $record) => ($record->data['body'] ?? '') === Str::limit($announcement->content, 140));
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => $id, 'type' => ParentAnnouncementNotification::class,
                'notifiable_type' => $parent->getMorphClass(), 'notifiable_id' => $parent->id,
                'data' => $data, 'read_at' => $legacy?->read_at,
                'created_at' => $announcement->published_at ?? $announcement->created_at ?? now(), 'updated_at' => now(),
            ]);
            if ($inserted && $push && ! $legacy) {
                DB::afterCommit(fn () => app(WebPushService::class)->send($parent, $payload));
            }
        } elseif ($existing !== $data) {
            DB::table('notifications')->where('id', $id)->update(['data' => $data, 'updated_at' => now()]);
        }

        return $id;
    }
}
