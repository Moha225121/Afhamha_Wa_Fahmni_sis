<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20)->default('mcq');
            $t->text('text');
            $t->decimal('score', 8, 2)->default(1);
            $t->unsignedInteger('order')->default(0);
            $t->timestamps();
        });

        Schema::create('exam_choices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $t->string('text');
            $t->boolean('is_correct')->default(false);
            $t->unsignedInteger('order')->default(0);
            $t->timestamps();
        });

        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $t->text('description')->nullable();
            $t->date('due_date')->nullable()->index();
            $t->decimal('max_score', 8, 2)->default(10);
            $t->string('attachment_path')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->timestamps();
        });

        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('submitted')->index();
            $t->text('notes')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('graded_at')->nullable();
            $t->decimal('score', 8, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['assignment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        foreach (['assignment_submissions', 'assignments', 'exam_choices', 'exam_questions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
