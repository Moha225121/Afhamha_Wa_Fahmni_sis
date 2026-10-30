<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_classroom', function (Blueprint $table): void {
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->primary(['assignment_id', 'classroom_id']);
            $table->index('classroom_id');
        });

        DB::table('assignments')->select(['id', 'classroom_id'])->orderBy('id')->chunk(500, function ($assignments): void {
            DB::table('assignment_classroom')->insertOrIgnore($assignments->map(fn ($assignment) => [
                'assignment_id' => $assignment->id,
                'classroom_id' => $assignment->classroom_id,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_classroom');
    }
};
