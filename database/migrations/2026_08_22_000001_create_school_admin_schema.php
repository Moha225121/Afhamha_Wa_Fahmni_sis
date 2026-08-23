<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('student')->index();
            $t->string('status', 20)->default('active')->index();
            $t->string('phone', 30)->nullable();
            $t->string('avatar_path')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->date('starts_at');
            $t->date('ends_at');
            $t->boolean('is_current')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('classrooms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('stage')->index();
            $t->string('section')->nullable();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['name', 'section', 'academic_year_id']);
        });
        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('student_number')->unique();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->date('birth_date')->nullable();
            $t->string('gender', 10)->nullable();
            $t->text('address')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('specialization')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('guardians', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('relationship')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('guardian_student', function (Blueprint $t) {
            $t->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->primary(['guardian_id', 'student_id']);
        });
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->string('stage')->index();
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->timestamps();
        });
        Schema::create('classroom_subject', function (Blueprint $t) {
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->primary(['classroom_id', 'subject_id']);
        });
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->unique(['teacher_id', 'classroom_id', 'subject_id']);
        });
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('day_of_week');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('room')->nullable();
            $t->timestamps();
            $t->index(['day_of_week', 'starts_at', 'ends_at']);
        });
        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->date('date')->index();
            $t->string('status', 20)->index();
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['student_id', 'date']);
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at')->index();
            $t->unsignedInteger('duration_minutes');
            $t->decimal('total_score', 8, 2);
            $t->string('status', 20)->default('draft')->index();
            $t->timestamps();
        });
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->decimal('score', 8, 2);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['exam_id', 'student_id']);
        });
        Schema::create('library_resources', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('category')->nullable();
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->string('file_path');
            $t->boolean('is_public')->default(false);
            $t->string('status', 20)->default('active');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('content');
            $t->string('audience', 30)->index();
            $t->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('published_at')->nullable()->index();
            $t->timestamp('expires_at')->nullable();
            $t->string('status', 20)->default('draft')->index();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('module')->index();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->ipAddress('ip_address')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('group')->default('system')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'announcements', 'library_resources', 'grades', 'exams', 'attendance_records', 'schedules', 'teacher_assignments', 'classroom_subject', 'subjects', 'guardian_student', 'guardians', 'teachers', 'students', 'classrooms', 'academic_years'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'status', 'phone', 'avatar_path', 'last_login_at']));
    }
};
