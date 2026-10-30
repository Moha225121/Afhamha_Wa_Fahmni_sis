<?php

namespace Tests\Feature;

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Offline SQL compilation only: this deliberately cannot open a PostgreSQL connection. */
class FcmPostgresGrammarTest extends TestCase
{
    public function test_pending_fcm_migration_compiles_native_postgresql_up_and_down_sql_without_connecting(): void
    {
        $connection = new PostgresConnection(function () {
            throw new \LogicException('Offline schema compilation must not open a database connection.');
        }, 'offline_grammar', '', ['driver' => 'pgsql']);
        $original = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require database_path('migrations/2026_10_18_000002_create_fcm_tokens.php');
            $up = array_column($connection->pretend(fn () => $migration->up()), 'query');
            $down = array_column($connection->pretend(fn () => $migration->down()), 'query');
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", $up);
        $this->assertStringContainsString('create table "fcm_tokens"', $sql);
        $this->assertStringContainsString('"user_id" bigint not null', $sql);
        $this->assertStringContainsString('"device_id" uuid not null', $sql);
        $this->assertStringContainsString('"token" text not null', $sql);
        $this->assertStringContainsString('unique ("device_id")', $sql);
        $this->assertStringContainsString('unique ("token_hash")', $sql);
        $this->assertStringContainsString('references "users" ("id") on delete cascade', $sql);
        $this->assertStringContainsString('alter table "push_subscriptions" add column "device_id" uuid null', $sql);
        $this->assertStringContainsString('create index "push_subscriptions_device_id_index"', $sql);
        $this->assertStringContainsString('drop index "push_subscriptions_device_id_index"', implode("\n", $down));
        $this->assertStringContainsString('drop column "device_id"', implode("\n", $down));
        $this->assertStringContainsString('drop table if exists "fcm_tokens"', implode("\n", $down));
        file_put_contents(sys_get_temp_dir().'/nasr-runtime-postgres-ddl.json', json_encode(['offlineOnly' => true, 'up' => $up, 'down' => $down], JSON_PRETTY_PRINT));
    }
}
