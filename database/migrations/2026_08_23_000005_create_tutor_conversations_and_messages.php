<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('محادثة جديدة');
            $table->timestamps();
            $table->index(['student_id', 'updated_at']);
        });

        Schema::create('tutor_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tutor_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->timestamps();
            $table->index(['tutor_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_messages');
        Schema::dropIfExists('tutor_conversations');
    }
};
