<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardSqliteTransaction();
        Schema::table('tutor_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->foreignId('teacher_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('creation_request_id')->nullable();
            $table->unique(['teacher_user_id', 'creation_request_id'], 'tutor_teacher_creation_unique');
            $table->index(['teacher_user_id', 'updated_at'], 'tutor_teacher_updated_index');
        });
    }

    public function down(): void
    {
        $this->guardSqliteTransaction();
        // Old application code can coexist with the added nullable columns.
        // Never erase teacher history or invent a student owner to roll back.
        if (DB::table('tutor_conversations')->whereNotNull('teacher_user_id')->orWhereNull('student_id')->exists()) {
            throw new RuntimeException('Cannot roll back while teacher conversations exist; retain this schema when rolling back application code.');
        }

        Schema::table('tutor_conversations', function (Blueprint $table): void {
            $table->dropUnique('tutor_teacher_creation_unique');
            $table->dropIndex('tutor_teacher_updated_index');
            $table->dropForeign(['teacher_user_id']);
            $table->dropColumn(['teacher_user_id', 'creation_request_id']);
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
        });
    }

    private function guardSqliteTransaction(): void
    {
        // SQLite rebuilds this table to change nullability. Its foreign-key
        // switch is ignored inside a transaction, which could cascade-delete
        // child messages when the old table is dropped during that rebuild.
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this SQLite migration outside a transaction to preserve tutor messages.');
        }
    }
};
