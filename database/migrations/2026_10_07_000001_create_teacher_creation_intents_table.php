<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_creation_intents', function (Blueprint $table): void {
            $table->uuid('token')->primary();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_creation_intents');
    }
};
