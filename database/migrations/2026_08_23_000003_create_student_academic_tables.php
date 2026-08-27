<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('type', 30);
            $table->json('options')->nullable();
            $table->text('correct_answer')->nullable();
            $table->decimal('score', 8, 2);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['exam_id', 'position']);
        });

        Schema::create('exam_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->string('status', 20)->default('in_progress')->index();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('maximum_score', 8, 2);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id', 'attempt_number']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('exam_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained()->restrictOnDelete();
            $table->text('question_text_snapshot');
            $table->string('question_type_snapshot', 30);
            $table->json('options_snapshot')->nullable();
            $table->text('correct_answer_snapshot')->nullable();
            $table->decimal('max_score', 8, 2);
            $table->text('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('awarded_score', 8, 2)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'exam_question_id']);
            $table->index('exam_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('exam_questions');
    }
};
