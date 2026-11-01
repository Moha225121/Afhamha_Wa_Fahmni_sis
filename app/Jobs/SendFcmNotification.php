<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [10, 60, 180];

    public bool $afterCommit = true;

    public int $uniqueFor = 3600;

    /** @param array{title: string, body: string, url?: string} $payload */
    public function __construct(
        public readonly int $userId,
        public readonly string $eventKey,
        public readonly int $deliveryId,
        public readonly array $payload,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->eventKey;
    }

    public function handle(FcmService $fcm): void
    {
        if (\Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->whereNotNull('sent_at')->exists()) {
            return;
        }
        $user = User::with('fcmDevices')->find($this->userId);
        if (! $user || $user->fcmDevices->isEmpty()) {
            \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
            return;
        }

        $fcm->sendToUser($user, $this->payload);
        \Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $this->deliveryId)->update(['sent_at' => now(), 'updated_at' => now()]);
    }
}
