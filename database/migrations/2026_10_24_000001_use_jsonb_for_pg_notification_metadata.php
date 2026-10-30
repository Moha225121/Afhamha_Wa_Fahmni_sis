<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'pgsql'
            || strtolower(Schema::getColumnType('notifications', 'data')) === 'jsonb') {
            return;
        }

        // Laravel's PostgreSQL JSON selectors require a JSON column, not the legacy TEXT type.
        // PostgreSQL runs this migration transactionally; invalid stored JSON aborts conversion.
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable('notifications');
        $column = $grammar->wrap('data');
        $connection->statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
    }

    public function down(): void
    {
        // Retain JSONB: existing JSON casts can still read it and rollback must not break selectors.
    }
};
