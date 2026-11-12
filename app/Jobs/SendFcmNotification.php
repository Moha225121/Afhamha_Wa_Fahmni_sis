<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\FcmDevice;
use App\Models\Announcement;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendFcmNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [10, 60, 180];

    public int $uniqueFor = 3600;

    /** @param array{title: string, body: string, url?: string} $payload */
    public function __construct(
        public readonly int $userId,
        public readonly string $eventKey,
        public readonly int $deliveryId,
        public readonly array $payload,
    ) {
        $this->onConnection('database');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->eventKey;
    }

    public function middleware(): array
    {
        // A released overlap also consumes an attempt. With four attempts, a
        // 60-second delay survives the full 120-second lease even if a worker
        // dies or an identical job collides immediately after lock acquisition.
        return [(new WithoutOverlapping('fcm-event:'.$this->deliveryId))->shared()->releaseAfter(60)->expireAfter(120)];
    }

    public function handle(FcmService $fcm): void
    {
        $event = \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->first();
        if (! $event || $event->sent_at || $event->cancelled_at) {
            return;
        }
        $user = User::with('fcmDevices')->find($this->userId);
        // Recheck the persisted announcement before a queued delivery or retry.
        if (str_starts_with($this->eventKey, 'announcement:') && ! $this->announcementIsVisibleTo($user)) {
            \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->whereNull('sent_at')->update([
                'cancelled_at' => now(), 'cancellation_reason' => 'announcement_unavailable', 'updated_at' => now(),
            ]);
            return;
        }
        if (str_starts_with($this->eventKey, 'results:') && ! $this->resultIsVisibleTo($user)) {
            \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->whereNull('sent_at')->update([
                'cancelled_at' => now(), 'cancellation_reason' => 'result_unavailable', 'updated_at' => now(),
            ]);
            return;
        }
        if (! $user || ! FcmDevice::canReceive($user) || $user->fcmDevices->isEmpty()) {
            \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
            return;
        }

        $fcm->sendToUser($user, $this->payload, $this->deliveryId);
        \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
    }

    private function announcementIsVisibleTo(?User $user): bool
    {
        $id = substr($this->eventKey, strlen('announcement:'));
        if (! $user || ! ctype_digit($id)) {
            return false;
        }
        $query = Announcement::whereKey($id);

        return match ($user->role) {
            'student' => $user->student && $query->visibleToStudent($user->student)->exists(),
            'parent' => $user->guardian && $query->visibleToParent($user->guardian)->exists(),
            default => false,
        };
    }

    private function resultIsVisibleTo(?User $user): bool
    {
        if (! $user || ! preg_match('/\Aresults:([1-9][0-9]*):([1-9][0-9]*)\z/', $this->eventKey, $parts)) {
            return false;
        }
        $studentId = filter_var($this->payload['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $authorized = $studentId && match ($user->role) {
            'student' => (int) $user->student?->id === $studentId,
            'parent' => $user->guardian?->students()->where('students.id', $studentId)->exists() ?? false,
            default => false,
        };

        return $authorized && \Illuminate\Support\Facades\DB::table('result_publications as p')
            ->join('exam_periods as e', 'e.id', '=', 'p.exam_period_id')
            ->where('p.exam_period_id', $parts[1])->where('p.student_id', $studentId)
            ->where('e.revision', $parts[2])->where('e.status', 'published')
            ->whereNotNull('e.published_at')->where('e.published_at', '<=', now())->exists();
    }
}
