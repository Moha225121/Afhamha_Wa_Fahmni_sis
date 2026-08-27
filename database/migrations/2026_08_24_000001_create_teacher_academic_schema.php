<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_choices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_question_id')->constrained()->cascadeOnDelete();
            $t->string('text');
            $t->boolean('is_correct')->default(false);
            $t->unsignedInteger('order')->default(0);
            $t->timestamps();
        });

        Schema::table('exam_questions', function (Blueprint $t) {
            $t->text('text')->nullable();
            $t->unsignedInteger('order')->default(0);
        });

        Schema::table('assignments', function (Blueprint $t) {
            $t->date('due_date')->nullable()->index();
            $t->decimal('max_score', 8, 2)->default(10);
        });

        Schema::table('assignment_submissions', function (Blueprint $t) {
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('score', 8, 2)->nullable();
            $t->timestamp('graded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $t) {
            $t->dropForeign(['graded_by']);
            $t->dropColumn(['feedback', 'graded_by', 'score', 'graded_at']);
        });
        Schema::table('assignments', function (Blueprint $t) {
            $t->dropIndex(['due_date']);
            $t->dropColumn(['due_date', 'max_score']);
        });
        Schema::table('exam_questions', function (Blueprint $t) {
            $t->dropColumn(['text', 'order']);
        });
        Schema::dropIfExists('exam_choices');
    }
};
