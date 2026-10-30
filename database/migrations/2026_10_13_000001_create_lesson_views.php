<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->timestamps();
            $table->unique(['lesson_id', 'student_id']);
            $table->index(['lesson_id', 'last_viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_views');
    }
};
