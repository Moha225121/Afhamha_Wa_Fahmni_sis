<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class FcmNotificationService
{
    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function record(User $user, string $eventKey, array $payload): bool
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:fcm-notification:'.$eventKey.':'.$user->id)->toString();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id,
            'type' => ParentPortalNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted) {
            DB::afterCommit(fn () => $this->dispatch($user, $eventKey, $payload));
        }

        return (bool) $inserted;
    }

    /** Call after the source event has committed; the queued job also enforces after-commit dispatch. */
    public function dispatch(User $user, string $eventKey, array $payload): void
    {
        if (! $user->fcmDevices()->exists()) {
            return;
        }

        $inserted = DB::table('fcm_notification_events')->insertOrIgnore([
            'user_id' => $user->id,
            'event_key' => $eventKey,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $inserted) return;

        $deliveryId = (int) DB::table('fcm_notification_events')->where('user_id', $user->id)->where('event_key', $eventKey)->value('id');
        DB::afterCommit(fn () => SendFcmNotification::dispatch($user->id, $eventKey, $deliveryId, $payload));
    }

    /** @param iterable<User> $users */
    public function recordForUsers(iterable $users, string $eventKey, callable $payloadFor): int
    {
        $inserted = 0;
        foreach ($users as $user) {
            $inserted += (int) $this->record($user, $eventKey, $payloadFor($user));
        }

        return $inserted;
    }

    /** @param array{title: string, body: string} $message */
    public function notifyClassroom(string $eventKey, int $classroomId, array $message): int
    {
        $students = User::query()->where('role', 'student')->where('status', 'active')
            ->whereHas('student', fn ($query) => $query->where('status', 'active')->where('classroom_id', $classroomId))
            ->with('student')->get();
        $parents = User::query()->where('role', 'parent')->where('status', 'active')
            ->whereHas('guardian', fn ($query) => $query->where('status', 'active')->whereHas('students', fn ($children) => $children->where('students.status', 'active')->where('students.classroom_id', $classroomId)))
            ->with('guardian')->get();

        $users = $students->concat($parents)->unique('id');

        return $this->recordForUsers($users, $eventKey, function (User $user) use ($message): array {
            $isStudent = $user->role === 'student';
            return [
                'title' => $message['title'],
                'body' => $message['body'],
                'url' => $isStudent ? ($message['student_url'] ?? route('student.assignments.index', [], false)) : ($message['parent_url'] ?? route('parent.assignments', [], false)),
                'category' => 'academic',
            ];
        });
    }
}
