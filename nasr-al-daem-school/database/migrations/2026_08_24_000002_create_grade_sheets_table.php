<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->json('sheet_data')->nullable();
            $table->timestamps();

            $table->unique(['teacher_id', 'classroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_sheets');
    }
};
