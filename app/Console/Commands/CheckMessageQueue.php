<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckMessageQueue extends Command
{
    protected $signature = 'messages:check-queue {--json : Print the read-only queue configuration and schema report as JSON}';

    protected $description = 'Check the database notifications queue without sending, retrying or changing messages';

    public function handle(): int
    {
        $connection = config('database.default');
        $checks = [
            'database_driver' => config('queue.connections.database.driver') === 'database',
            'queue_uses_application_database' => (config('queue.connections.database.connection') ?: $connection) === $connection,
            'batching_uses_application_database' => (config('queue.batching.database') ?: $connection) === $connection,
            // Message, receipt and durable job must commit in the same transaction.
            'transactional_enqueue' => config('queue.connections.database.after_commit', false) === false,
            'retry_after_exceeds_worker_timeout' => (int) config('queue.connections.database.retry_after', 0) > 60,
            // The Linux service relies on Laravel's graceful SIGTERM and alarm timeout handlers.
            'linux_worker_signals_and_timeout' => PHP_OS_FAMILY !== 'Linux'
                || (function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && function_exists('pcntl_alarm')),
        ];
        $report = [
            'ready' => false,
            'scope' => 'current_cli_configuration_and_database_schema',
            'connection' => 'database',
            'queue' => 'notifications',
            'checks' => $checks,
            'pending_jobs' => null,
            'reserved_jobs' => null,
            'worker_consumption' => 'not_tested',
            'push_delivery' => 'not_tested',
        ];

        // Never connect to an unexpected secondary database to inspect its data.
        if (! in_array(false, $checks, true)) {
            try {
                $database = DB::connection($connection);
                $schema = $database->getSchemaBuilder();
                $jobsTable = config('queue.connections.database.table', 'jobs');
                $batchesTable = config('queue.batching.table', 'job_batches');
                $tables = [
                    'jobs_schema' => [$jobsTable, ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']],
                    'batches_schema' => [$batchesTable, ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at']],
                    'notifications_schema' => ['notifications', ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at']],
                    'messages_schema' => ['messages', ['id', 'conversation_id', 'sender_id', 'body', 'client_request_id', 'created_at', 'updated_at']],
                    'conversation_receipts_schema' => ['conversation_message_receipts', ['notification_id', 'message_id', 'recipient_user_id', 'delivered_at', 'viewed_at', 'created_at', 'updated_at']],
                    'teacher_receipts_schema' => ['teacher_message_receipts', ['notification_id', 'teacher_user_id', 'recipient_user_id', 'dispatch_id', 'delivered_at', 'viewed_at', 'created_at', 'updated_at']],
                ];
                foreach ($tables as $check => [$table, $columns]) {
                    $report['checks'][$check] = is_string($table) && $table !== '' && $schema->hasColumns($table, $columns);
                }
                if ($report['checks']['jobs_schema']) {
                    $jobs = $database->table($jobsTable)->where('queue', 'notifications');
                    $report['pending_jobs'] = (clone $jobs)->count();
                    $report['reserved_jobs'] = (clone $jobs)->whereNotNull('reserved_at')->count();
                }
                $report['checks']['database_readable'] = true;
            } catch (Throwable) {
                // SQL errors can contain connection credentials and private values.
                $report['checks']['database_readable'] = false;
            }
        }
        $report['ready'] = ! in_array(false, $report['checks'], true);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            if ($report['ready']) {
                $this->info('Message queue configuration and schema are ready for database/notifications.');
                $this->line('Pending jobs: '.$report['pending_jobs'].'; reserved jobs: '.$report['reserved_jobs'].'.');
            } else {
                $this->error('Message queue readiness failed: '.implode(', ', array_keys(array_filter($report['checks'], fn (bool $passed): bool => ! $passed))).'.');
                $this->line('Inspect the existing configuration and migration status. This check does not change either.');
            }
            $this->line('Read-only check: running worker consumption, PHP-FPM configuration and phone delivery are not verified.');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
