<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\FcmDevice;
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
        if (\Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->whereNotNull('sent_at')->exists()) {
            return;
        }
        $user = User::with('fcmDevices')->find($this->userId);
        if (! $user || ! FcmDevice::canReceive($user) || $user->fcmDevices->isEmpty()) {
            \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
            return;
        }

        $fcm->sendToUser($user, $this->payload, $this->deliveryId);
        \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
    }
}
