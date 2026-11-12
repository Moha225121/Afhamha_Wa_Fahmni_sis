<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Support\NotificationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Ramsey\Uuid\Uuid;
use Throwable;

class FcmNotificationService
{
    private bool $deliveryPending = false;

    public function hasPendingDelivery(): bool
    {
        return $this->deliveryPending;
    }

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function record(User $user, string $eventKey, array $payload): bool
    {
        return DB::transaction(function () use ($user, $eventKey, $payload): bool {
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

            // Persist the outbox with the internal notification. A process exit after
            // commit must not leave a notification that retryPending cannot discover.
            // Only queue insertion waits for commit; a repeated request can recover it.
            $this->dispatch($user, $eventKey, $payload);

            return (bool) $inserted;
        });
    }

    /** The event joins any source transaction; only queue insertion waits for commit. */
    public function dispatch(User $user, string $eventKey, array $payload): void
    {
        if (! $user->fcmDevices()->exists()) {
            return;
        }

        DB::table('fcm_notification_events')->insertOrIgnore([
            'user_id' => $user->id,
            'event_key' => $eventKey,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'queued_at' => null,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deliveryId = (int) DB::table('fcm_notification_events')->where('user_id', $user->id)->where('event_key', $eventKey)->value('id');
        DB::afterCommit(fn () => $this->enqueueEvent($deliveryId));
    }

    public function retryPending(int $limit = 100): int
    {
        $ids = DB::table('fcm_notification_events')->whereNull('queued_at')->whereNull('sent_at')->whereNull('cancelled_at')
            ->whereNotNull('payload')->orderBy('id')->limit($limit)->pluck('id');
        $queued = 0;
        foreach ($ids as $id) {
            $queued += (int) $this->enqueueEvent((int) $id);
        }

        return $queued;
    }

    private function enqueueEvent(int $deliveryId): bool
    {
        try {
            return DB::transaction(function () use ($deliveryId): bool {
                $event = DB::table('fcm_notification_events')->where('id', $deliveryId)->lockForUpdate()->first();
                // Legacy rows have no durable payload. Never invent or replay their notification.
                if (! $event || $event->sent_at || $event->cancelled_at || $event->queued_at || $event->payload === null) {
                    return false;
                }
                $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
                $job = new SendFcmNotification($event->user_id, $event->event_key, $event->id, $payload);
                $queueDatabase = config('queue.connections.database.connection') ?: config('database.default');
                if ($queueDatabase !== config('database.default')) {
                    throw new \RuntimeException('FCM outbox requires the database queue to use the event database connection.');
                }
                // The source transaction has committed. With the default database queue, this insert
                // and queued_at share this transaction; the event row lock prevents duplicate enqueue.
                Queue::connection('database')->push($job->beforeCommit());
                DB::table('fcm_notification_events')->where('id', $deliveryId)->update(['queued_at' => now(), 'updated_at' => now()]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->deliveryPending = true;
            Log::warning('FCM event is stored and awaiting queue retry.', ['event_id' => $deliveryId, 'exception_type' => $exception::class]);

            return false;
        }
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
        $context = NotificationContext::academic($classroomId, isset($message['teacher_id']) ? (int) $message['teacher_id'] : null);

        return $this->recordForUsers($users, $eventKey, function (User $user) use ($message, $context): array {
            $isStudent = $user->role === 'student';
            return [
                'title' => $message['title'],
                'body' => $message['body'],
                'url' => $isStudent ? ($message['student_url'] ?? route('student.assignments.index', [], false)) : ($message['parent_url'] ?? route('parent.assignments', [], false)),
                'category' => 'academic',
            ] + $context;
        });
    }
}
