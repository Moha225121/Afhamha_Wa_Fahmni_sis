<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teacherIdsByUserId = DB::table('teachers')->pluck('id', 'user_id')->all();
        $existingTeacherIds = array_fill_keys(array_values($teacherIdsByUserId), true);
        $rows = DB::table('grade_sheets')->orderBy('id')->get(['id', 'teacher_id', 'classroom_id']);
        $mappedRows = [];
        $uniquePairs = [];

        foreach ($rows as $row) {
            // Older grade controllers saved teachers.id despite the users foreign key.
            // Accept either representation, but never guess when they identify different teachers.
            $directTeacherId = isset($existingTeacherIds[$row->teacher_id]) ? (int) $row->teacher_id : null;
            $mappedTeacherId = isset($teacherIdsByUserId[$row->teacher_id])
                ? (int) $teacherIdsByUserId[$row->teacher_id]
                : null;
            if ($directTeacherId !== null && $mappedTeacherId !== null && $directTeacherId !== $mappedTeacherId) {
                throw new RuntimeException('Ambiguous teacher reference for grade sheet '.$row->id.'. Resolve its teacher before migrating.');
            }
            $teacherId = $directTeacherId ?? $mappedTeacherId;
            if ($teacherId === null) {
                throw new RuntimeException('Cannot map grade sheet '.$row->id.' to a teacher record.');
            }

            $pair = $teacherId.':'.$row->classroom_id;
            if (isset($uniquePairs[$pair])) {
                throw new RuntimeException('Grade sheets contain duplicate teacher/classroom records after migration.');
            }

            $uniquePairs[$pair] = true;
            $mappedRows[$row->id] = $teacherId;
        }

        Schema::table('grade_sheets', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
            $table->dropUnique(['teacher_id', 'classroom_id']);
        });

        foreach ($mappedRows as $id => $teacherId) {
            DB::table('grade_sheets')->where('id', $id)->update(['teacher_id' => $teacherId]);
        }

        Schema::table('grade_sheets', function (Blueprint $table): void {
            $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
            $table->unique(['teacher_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        $userIdsByTeacherId = DB::table('teachers')->pluck('user_id', 'id')->all();
        $rows = DB::table('grade_sheets')->orderBy('id')->get(['id', 'teacher_id']);
        $mappedRows = [];

        foreach ($rows as $row) {
            $userId = $userIdsByTeacherId[$row->teacher_id] ?? null;
            if ($userId === null) {
                throw new RuntimeException('Cannot map grade sheet '.$row->id.' to a user account.');
            }
            $mappedRows[$row->id] = $userId;
        }

        Schema::table('grade_sheets', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
            $table->dropUnique(['teacher_id', 'classroom_id']);
        });

        foreach ($mappedRows as $id => $userId) {
            DB::table('grade_sheets')->where('id', $id)->update(['teacher_id' => $userId]);
        }

        Schema::table('grade_sheets', function (Blueprint $table): void {
            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['teacher_id', 'classroom_id']);
        });
    }
};
