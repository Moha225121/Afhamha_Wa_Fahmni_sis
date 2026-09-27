<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $t): void {
            $t->time('arrival_time')->nullable()->after('status');
            $t->unsignedInteger('late_minutes')->nullable()->after('arrival_time');
            $t->text('excuse_reason')->nullable()->after('late_minutes');
            $t->string('excuse_document')->nullable()->after('excuse_reason');
            $t->foreignId('updated_by')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
        });
        Schema::create('supervisor_class_assignments', function (Blueprint $t): void {
            $t->id(); $t->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete(); $t->foreignId('classroom_id')->constrained()->cascadeOnDelete(); $t->timestamps(); $t->unique(['supervisor_id','classroom_id']);
        });
        Schema::create('student_notes', function (Blueprint $t): void {
            $t->id(); $t->foreignId('student_id')->constrained()->cascadeOnDelete(); $t->foreignId('supervisor_id')->constrained('users')->restrictOnDelete(); $t->string('type', 30)->index(); $t->string('title'); $t->text('content'); $t->string('visibility', 20)->default('internal')->index(); $t->boolean('requires_guardian_call')->default(false); $t->timestamps();
        });
        Schema::create('guardian_calls', function (Blueprint $t): void {
            $t->id(); $t->foreignId('student_id')->constrained()->cascadeOnDelete(); $t->foreignId('supervisor_id')->constrained('users')->restrictOnDelete(); $t->foreignId('student_note_id')->nullable()->constrained()->nullOnDelete(); $t->string('category', 40)->default('other'); $t->string('reason'); $t->text('details')->nullable(); $t->text('guardian_visible_details')->nullable(); $t->string('status', 20)->default('pending')->index(); $t->date('requested_date'); $t->dateTime('meeting_date')->nullable(); $t->text('guardian_response')->nullable(); $t->timestamp('resolved_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('guardian_calls'); Schema::dropIfExists('student_notes'); Schema::dropIfExists('supervisor_class_assignments');
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropConstrainedForeignId('updated_by'));
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn(['arrival_time','late_minutes','excuse_reason','excuse_document']));
    }
};
