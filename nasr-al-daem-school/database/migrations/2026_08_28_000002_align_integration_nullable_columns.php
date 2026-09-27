<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable()->change();
        });

        Schema::table('assignment_attachments', function (Blueprint $table): void {
            $table->string('disk', 50)->nullable()->change();
        });

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->dateTime('due_at')->nullable(false)->change();
        });

        Schema::table('assignment_attachments', function (Blueprint $table): void {
            $table->string('disk', 50)->nullable(false)->change();
        });

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable(false)->change();
        });
    }
};
