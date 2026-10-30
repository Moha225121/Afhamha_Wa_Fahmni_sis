<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NisbiyaMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Verifies real PostgreSQL transactional DDL.');
        }
    }

    private function legacyNotification(string $data): string
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'qa-migration-only',
            'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 42,
            'data' => $data, 'read_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_jsonb_upgrade_preserves_existing_id_content_and_read_state(): void
    {
        $data = ['title' => 'QA-only preserved notification', 'student_id' => 42];
        $id = $this->legacyNotification(json_encode($data));
        $before = DB::table('notifications')->where('id', $id)->first();
        $migration = require database_path('migrations/2026_10_24_000001_use_jsonb_for_pg_notification_metadata.php');

        DB::transaction(fn () => $migration->up());

        $after = DB::table('notifications')->where('id', $id)->first();
        $this->assertSame('jsonb', Schema::getColumnType('notifications', 'data'));
        $this->assertSame($before->id, $after->id);
        $this->assertEquals($data, json_decode($after->data, true));
        $this->assertSame($before->read_at, $after->read_at);
        $this->assertSame($before->created_at, $after->created_at);
    }

    public function test_invalid_historical_json_aborts_without_deleting_or_rewriting_the_record(): void
    {
        $id = $this->legacyNotification('{invalid historical JSON');
        $before = DB::table('notifications')->where('id', $id)->first();
        $migration = require database_path('migrations/2026_10_24_000001_use_jsonb_for_pg_notification_metadata.php');

        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('Invalid historical JSON must block the migration.');
        } catch (QueryException $exception) {
            $this->assertSame('22P02', $exception->errorInfo[0]);
        }

        $this->assertSame('text', Schema::getColumnType('notifications', 'data'));
        $this->assertEquals($before, DB::table('notifications')->where('id', $id)->first());
    }
}
