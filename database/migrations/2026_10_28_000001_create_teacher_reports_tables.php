<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('config');
            $table->json('data');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['teacher_id', 'classroom_id', 'updated_at']);
        });
        Schema::create('teacher_report_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 160);
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assessment_type', 20)->nullable();
            $table->date('assessment_date')->nullable();
            $table->timestamps();
            $table->unique(['teacher_id', 'classroom_id', 'source_key'], 'teacher_report_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_report_sources');
        Schema::dropIfExists('teacher_reports');
    }
};
