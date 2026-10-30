<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageQueueReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'sync',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.retry_after' => 90,
            'queue.connections.database.after_commit' => false,
            'queue.batching.database' => 'sqlite',
            'queue.batching.table' => 'job_batches',
        ]);
        DB::purge('sqlite');
        // A private in-memory fixture: no migrations, seeders or application DB.
        $tables = [
            'jobs' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
            'job_batches' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
            'notifications' => ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at'],
            'messages' => ['id', 'conversation_id', 'sender_id', 'body', 'client_request_id', 'created_at', 'updated_at'],
            'conversation_message_receipts' => ['notification_id', 'message_id', 'recipient_user_id', 'delivered_at', 'viewed_at', 'created_at', 'updated_at'],
            'teacher_message_receipts' => ['notification_id', 'teacher_user_id', 'recipient_user_id', 'dispatch_id', 'delivered_at', 'viewed_at', 'created_at', 'updated_at'],
        ];
        foreach ($tables as $table => $columns) {
            Schema::create($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->text($column)->nullable();
                }
            });
        }
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function report(int $expectedExit = 0): array
    {
        $exit = Artisan::call('messages:check-queue', ['--json' => true, '--no-interaction' => true]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_sync_default_is_supported_and_report_counts_only_notification_jobs_without_writing(): void
    {
        DB::table('jobs')->insert([
            ['id' => '1', 'queue' => 'notifications', 'payload' => 'private-message-one', 'reserved_at' => null],
            ['id' => '2', 'queue' => 'notifications', 'payload' => 'private-message-two', 'reserved_at' => '123'],
            ['id' => '3', 'queue' => 'default', 'payload' => 'private-unrelated-job', 'reserved_at' => null],
        ]);
        $before = DB::table('jobs')->get()->toJson();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $report = $this->report();

        $this->assertTrue($report['ready']);
        $this->assertSame(2, $report['pending_jobs']);
        $this->assertSame(1, $report['reserved_jobs']);
        $this->assertSame('not_tested', $report['worker_consumption']);
        $this->assertSame('not_tested', $report['push_delivery']);
        $this->assertSame($before, DB::table('jobs')->get()->toJson());
        $this->assertStringNotContainsString('private-', Artisan::output());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop|truncate|replace)\b/i', $query);
        }
    }

    #[DataProvider('invalidConfiguration')]
    public function test_unsupported_queue_configuration_fails_without_inspecting_a_secondary_database(string $key, mixed $value, string $check): void
    {
        config([$key => $value]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $report = $this->report(1);

        $this->assertFalse($report['ready']);
        $this->assertFalse($report['checks'][$check]);
        $this->assertNull($report['pending_jobs']);
        $this->assertSame([], $queries);
        $this->assertStringNotContainsString('private-secondary-database', Artisan::output());
    }

    public static function invalidConfiguration(): array
    {
        return [
            'driver' => ['queue.connections.database.driver', 'sync', 'database_driver'],
            'queue database' => ['queue.connections.database.connection', 'private-secondary-database', 'queue_uses_application_database'],
            'batch database' => ['queue.batching.database', 'private-secondary-database', 'batching_uses_application_database'],
            'short retry' => ['queue.connections.database.retry_after', 60, 'retry_after_exceeds_worker_timeout'],
            'after commit' => ['queue.connections.database.after_commit', true, 'transactional_enqueue'],
        ];
    }

    public function test_missing_receipt_schema_is_reported_without_running_migrations(): void
    {
        Schema::drop('conversation_message_receipts');

        $report = $this->report(1);

        $this->assertFalse($report['checks']['conversation_receipts_schema']);
        $this->assertFalse(Schema::hasTable('conversation_message_receipts'));
    }

    public function test_missing_idempotency_column_prevents_a_false_ready_report(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('client_request_id'));

        $this->assertFalse($this->report(1)['checks']['messages_schema']);
    }

    public function test_missing_jobs_table_does_not_query_payloads_or_repair_the_database(): void
    {
        Schema::drop('jobs');

        $report = $this->report(1);

        $this->assertFalse($report['checks']['jobs_schema']);
        $this->assertNull($report['pending_jobs']);
        $this->assertFalse(Schema::hasTable('jobs'));
    }

    public function test_custom_queue_table_and_null_batch_connection_fallback_are_supported(): void
    {
        Schema::rename('jobs', 'custom_jobs');
        config(['queue.connections.database.table' => 'custom_jobs', 'queue.batching.database' => null]);

        $this->assertTrue($this->report()['ready']);
    }

    public function test_database_errors_return_a_redacted_failure(): void
    {
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => '/private-secondary-database/missing.sqlite']);

        $report = $this->report(1);

        $this->assertFalse($report['checks']['database_readable']);
        $this->assertStringNotContainsString('private-secondary-database', Artisan::output());
        $this->assertStringNotContainsString('SQLSTATE', Artisan::output());
    }
}
