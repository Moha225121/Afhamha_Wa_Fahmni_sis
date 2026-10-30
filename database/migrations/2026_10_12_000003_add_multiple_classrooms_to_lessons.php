<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lesson_classroom')) {
            Schema::create('lesson_classroom', function (Blueprint $table): void {
                $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
                $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
                $table->primary(['lesson_id', 'classroom_id']);
                $table->index('classroom_id');
            });
        }

        DB::table('lessons')
            ->whereNotNull('classroom_id')
            ->select(['id', 'classroom_id'])
            ->orderBy('id')
            ->chunkById(500, function ($lessons): void {
                DB::table('lesson_classroom')->insertOrIgnore(
                    $lessons->map(fn ($lesson) => [
                        'lesson_id' => $lesson->id,
                        'classroom_id' => $lesson->classroom_id,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_classroom');
    }
};
