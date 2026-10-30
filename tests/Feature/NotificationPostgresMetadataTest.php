<?php

namespace Tests\Feature;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\PostgresBuilder;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Offline grammar checks; real PostgreSQL semantics are verified separately without app data. */
class NotificationPostgresMetadataTest extends TestCase
{
    public static function legacyPostgresTypes(): array
    {
        return [['text'], ['json']];
    }

    #[DataProvider('legacyPostgresTypes')]
    public function test_postgres_metadata_conversion_uses_a_prefix_safe_explicit_jsonb_cast(string $legacyType): void
    {
        $connection = new PostgresConnection(fn () => throw new LogicException('Offline grammar checks cannot connect to a database.'),
            'offline_notification_metadata', 'school_', ['driver' => 'pgsql']);
        $connection->useDefaultSchemaGrammar();
        $builder = Mockery::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getColumnType')->once()->with('notifications', 'data')->andReturn($legacyType);
        $original = Schema::getFacadeRoot();
        Schema::swap($builder);
        try {
            $migration = require database_path('migrations/2026_10_24_000001_use_jsonb_for_pg_notification_metadata.php');
            $this->assertTrue($migration->withinTransaction);
            $this->assertTrue($connection->getSchemaGrammar()->supportsSchemaTransactions());
            $queries = array_column($connection->pretend(fn () => $migration->up()), 'query');
            $rollback = $connection->pretend(fn () => $migration->down());
        } finally {
            Schema::swap($original);
        }

        $this->assertSame(['ALTER TABLE "school_notifications" ALTER COLUMN "data" TYPE jsonb USING "data"::jsonb'], $queries);
        $this->assertSame([], $rollback);
    }

    public function test_an_existing_jsonb_column_is_left_unchanged(): void
    {
        $connection = new PostgresConnection(fn () => throw new LogicException('No PostgreSQL connection is allowed.'),
            'offline_notification_metadata', '', ['driver' => 'pgsql']);
        $builder = Mockery::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getColumnType')->once()->with('notifications', 'data')->andReturn('jsonb');
        $original = Schema::getFacadeRoot();
        Schema::swap($builder);
        try {
            $migration = require database_path('migrations/2026_10_24_000001_use_jsonb_for_pg_notification_metadata.php');
            $queries = $connection->pretend(fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }

        $this->assertSame([], $queries);
    }

    public function test_other_database_drivers_do_not_inspect_or_change_the_metadata_column(): void
    {
        $connection = new SQLiteConnection(fn () => throw new LogicException('No SQLite connection is allowed.'),
            ':memory:', '', ['driver' => 'sqlite']);
        $builder = Mockery::mock(SQLiteBuilder::class, [$connection])->makePartial();
        $builder->shouldNotReceive('getColumnType');
        $original = Schema::getFacadeRoot();
        Schema::swap($builder);
        try {
            $migration = require database_path('migrations/2026_10_24_000001_use_jsonb_for_pg_notification_metadata.php');
            $queries = $connection->pretend(fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }

        $this->assertSame([], $queries);
    }
}
