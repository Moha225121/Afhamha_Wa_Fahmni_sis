<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_report_dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('teacher_reports')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('fingerprint', 64);
            $table->json('response');
            $table->timestamps();
            $table->index(['teacher_id', 'report_id']);
        });
        Schema::create('teacher_report_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('dispatch_id')->constrained('teacher_report_dispatches')->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('teacher_reports')->cascadeOnDelete();
            $table->unsignedInteger('report_revision');
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('audience', 10);
            $table->json('snapshot');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_user_id', 'audience', 'created_at'], 'teacher_report_delivery_inbox');
            $table->index(['dispatch_id', 'recipient_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_report_deliveries');
        Schema::dropIfExists('teacher_report_dispatches');
    }
};
