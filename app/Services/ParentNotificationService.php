<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class ParentNotificationService
{
    public function __construct(private readonly PushNotificationService $push) {}

    /** @param array{title: string, body: string, url?: string, category?: string, student_id?: int|null} $payload */
    public function send(User $user, array $payload): array
    {
        $user->notify(new ParentPortalNotification($payload));

        return $this->transportAfterCommit($user, $payload);
    }

    /** Idempotent queued delivery using the existing notification record and transports. */
    public function sendOnce(User $user, array $payload, string $notificationId): array
    {
        $notification = new ParentPortalNotification($payload);
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $notificationId, 'type' => ParentPortalNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => json_encode($notification->toDatabase($user), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $inserted) {
            return ['stored' => false, 'fcm_accepted' => 0, 'web_push_accepted' => 0];
        }

        return ['stored' => true] + $this->transportAfterCommit($user, array_replace($payload, ['notification_id' => $notificationId]));
    }

    private function transportAfterCommit(User $user, array $payload): array
    {
        $result = ['fcm_accepted' => 0, 'web_push_accepted' => 0, 'deferred' => true];
        DB::afterCommit(function () use ($user, $payload, &$result): void {
            try {
                $result = $this->push->send($user, $payload);
            } catch (\Throwable) {
                // The committed inbox must survive a transport failure; never log message content.
                Log::warning('Portal push failed; inbox notification retained.');
            }
        });

        return $result;
    }

    /** Persist the inbox copy in the sender's transaction, without contacting a device provider. */
    public function storeForQueuedMessage(User $user, array $payload, string $notificationId): bool
    {
        return (bool) DB::table('notifications')->insertOrIgnore($this->queuedMessageRecord($user, $payload, $notificationId));
    }

    /** Build rows separately so a large confirmed audience can be inserted in bounded batches. */
    public function queuedMessageRecord(User $user, array $payload, string $notificationId): array
    {
        $data = (new ParentPortalNotification($payload))->toDatabase($user);
        $data['_message_push_pending'] = true;

        return [
            'id' => $notificationId, 'type' => ParentPortalNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** Old queued messages are stored here too; old delivered rows without the marker are never re-pushed. */
    public function sendQueuedMessage(User $user, array $payload, string $notificationId): array
    {
        $stored = $this->storeForQueuedMessage($user, $payload, $notificationId);
        $claimed = DB::transaction(function () use ($user, $notificationId): bool {
            $query = DB::table('notifications')->where('id', $notificationId)
                ->where('type', ParentPortalNotification::class)
                ->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id);
            $record = (clone $query)->lockForUpdate()->first(['data']);
            $data = $record ? json_decode($record->data, true, 512, JSON_THROW_ON_ERROR) : [];
            if (($data['_message_push_pending'] ?? false) !== true) {
                return false;
            }
            // Claim before contacting providers, matching sendOnce's at-most-once push semantics.
            unset($data['_message_push_pending']);
            $query->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return true;
        });

        return ['stored' => $stored, 'push_attempted' => $claimed] + ($claimed
            ? $this->push->send($user, array_replace($payload, ['notification_id' => $notificationId]))
            : ['fcm_accepted' => 0, 'web_push_accepted' => 0]);
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

    /** Notify guardians of students in the selected classes when new learning work is published. */
    public function sendToClassrooms(array $classroomIds, array $payload, ?string $eventKey = null): int
    {
        $students = Student::query()->whereIn('classroom_id', $classroomIds)->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->with('guardians.user')->get();
        $parents = $students->flatMap(fn (Student $student) => $student->guardians->pluck('user'))
            ->filter(fn (?User $user) => $user?->isParent())->unique('id')->values();
        $parents->each(function (User $parent) use ($payload, $eventKey): void {
            if ($eventKey === null) {
                $this->send($parent, $payload);

                return;
            }

            $this->sendOnce($parent, $payload, $this->eventNotificationId($eventKey, $parent->id));
        });

        return $parents->count();
    }

    /** Notify active students in the selected classrooms using the existing delivery transports. */
    public function sendToStudents(array $classroomIds, array $payload, ?string $eventKey = null, ?string $studentUrl = null): int
    {
        $students = Student::query()->whereIn('classroom_id', $classroomIds)->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('role', 'student')->where('status', 'active'))
            ->with('user')->get();

        $students->each(function (Student $student) use ($payload, $eventKey, $studentUrl): void {
            $data = array_replace($payload, [
                'student_id' => $student->id,
                'url' => $studentUrl ?? app(NotificationDestination::class)->for($student->user, array_replace($payload, ['student_id' => $student->id])),
            ]);
            $user = $student->user;
            if ($eventKey === null) {
                $this->send($user, $data);

                return;
            }

            $this->sendOnce($user, $data, $this->eventNotificationId($eventKey, $user->id));
        });

        return $students->count();
    }

    private function eventNotificationId(string $eventKey, int $userId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:'.$eventKey.':'.$userId)->toString();
    }
}
