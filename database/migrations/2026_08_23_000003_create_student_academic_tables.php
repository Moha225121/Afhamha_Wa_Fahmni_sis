<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->timestamp('due_at')->index();
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->index(['classroom_id', 'status', 'published_at', 'due_at'], 'assignments_student_list_idx');
            $table->index(['teacher_id', 'due_at'], 'assignments_teacher_list_idx');
            $table->index(['classroom_id', 'subject_id'], 'assignments_class_subject_idx');
        });

        Schema::create('assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['disk', 'file_path']);
            $table->index(['assignment_id', 'sort_order']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at');
            $table->string('status', 20)->default('submitted')->index();
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('exam_questions', function (Blueprint $table) {
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

        Schema::create('exam_attempts', function (Blueprint $table) {
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

        Schema::create('exam_answers', function (Blueprint $table) {
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
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_attachments');
        Schema::dropIfExists('assignments');
    }
};
