<?php

namespace App\Services;

use App\Jobs\SendTeacherNotification;
use App\Models\AuditLog;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherMessageRecoveryService
{
    public const PAGE_SIZE = 250;

    /** Restore only inbox storage; the original queued jobs remain responsible for phone alerts. */
    public function recover(User $actor, string $dispatchId, int $afterId = 0): array
    {
        abort_unless($actor->isTeacher() && $actor->teacher?->status === 'active', 403);
        $record = AuditLog::where('user_id', $actor->id)->where('module', 'teacher_notifications')->where('action', 'queued')
            ->where('new_values->dispatch_id', $dispatchId)->firstOrFail();
        $batchId = $record->new_values['batch_id'] ?? null;
        abort_unless(is_string($batchId) && $batchId !== '', 404);

        $connection = config('database.default');
        if (config('queue.connections.database.driver') !== 'database'
            || (config('queue.connections.database.connection') ?: $connection) !== $connection
            || (config('queue.batching.database') ?: $connection) !== $connection) {
            throw ValidationException::withMessages(['recovery' => 'إعداد استكمال الرسائل غير متاح حالياً. تواصل مع مسؤول النظام.']);
        }
        $batch = Bus::findBatch($batchId);
        abort_unless($batch && $batch->name === 'teacher-notification:'.$dispatchId && ! $batch->cancelled(), 404);
        try {
            $rows = DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', 'notifications')
                ->where('id', '>', $afterId)->orderBy('id')->limit(self::PAGE_SIZE + 1)->get(['id', 'payload']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['recovery' => 'تعذّر قراءة الرسائل المعلقة. لم يتم تغيير طلبات الإرسال.']);
        }

        $hasMore = $rows->count() > self::PAGE_SIZE;
        $page = $rows->take(self::PAGE_SIZE);
        $restored = 0;
        $failed = 0;
        foreach ($page as $row) {
            $job = $this->ownedJob($row->payload, $actor, $dispatchId, $batchId);
            if (! $job) {
                continue;
            }
            try {
                $restored += (int) DB::transaction(fn () => $job->restoreInbox(app(TeacherNotificationAudience::class), app(ParentNotificationService::class)));
            } catch (Throwable) {
                // Never expose encrypted payloads, private messages or provider/SQL diagnostics.
                $failed++;
            }
        }

        return ['restored' => $restored, 'failed' => $failed, 'next' => $hasMore ? (int) $page->last()->id : null];
    }

    private function ownedJob(string $payload, User $actor, string $dispatchId, string $batchId): ?SendTeacherNotification
    {
        try {
            $envelope = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            if (($envelope['displayName'] ?? null) !== SendTeacherNotification::class
                || ($envelope['job'] ?? null) !== 'Illuminate\\Queue\\CallQueuedHandler@call'
                || ($envelope['data']['commandName'] ?? null) !== SendTeacherNotification::class
                || ! is_string($envelope['data']['command'] ?? null)) {
                return null;
            }
            // Laravel encrypts the serialized command as a serialized string. Decode that
            // outer string without ever instantiating objects from its authenticated data.
            $serialized = @unserialize(Crypt::decryptString($envelope['data']['command']), ['allowed_classes' => false, 'max_depth' => 4]);
            if (! is_string($serialized)) {
                return null;
            }
            // Queue data is authenticated ciphertext. Still permit only this exact, data-only job class.
            $job = @unserialize($serialized, ['allowed_classes' => [SendTeacherNotification::class], 'max_depth' => 32]);
            if (! $job instanceof SendTeacherNotification || get_class($job) !== SendTeacherNotification::class
                || $job->actorId !== $actor->id || $job->dispatchId !== $dispatchId || $job->batchId !== $batchId
                || ! app(TeacherNotificationAudience::class)->isMessaging($job->filters)
                || ! TeacherMessageReceipt::whereKey(SendTeacherNotification::notificationId($dispatchId, $job->recipientId))
                    ->where('teacher_user_id', $actor->id)->where('recipient_user_id', $job->recipientId)->where('dispatch_id', $dispatchId)->exists()) {
                return null;
            }

            return $job;
        } catch (Throwable) {
            return null;
        }
    }
}
