<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_periods', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->string('term', 80);
            $t->string('exam_type', 80);
            $t->string('grade')->nullable();
            $t->foreignId('classroom_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('draft')->index();
            $t->timestamp('scheduled_at')->nullable()->index();
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::table('exams', function (Blueprint $t): void {
            $t->foreignId('exam_period_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('legacy_results_published')->default(false);
        });
        // Preserve visibility of existing published exams; new exams must be published through a period.
        DB::table('exams')->where('status', 'published')->update(['legacy_results_published' => true]);
        Schema::create('result_marks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('exam_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('subject_id')->constrained()->restrictOnDelete();
            $t->decimal('score', 10, 2);
            $t->decimal('maximum_score', 10, 2);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['exam_period_id', 'student_id', 'subject_id']);
        });
        Schema::create('result_publications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('exam_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->json('payload');
            $t->timestamps();
            $t->unique(['exam_period_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_publications');
        Schema::dropIfExists('result_marks');
        Schema::table('exams', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('exam_period_id');
            $t->dropColumn('legacy_results_published');
        });
        Schema::dropIfExists('exam_periods');
    }
};
