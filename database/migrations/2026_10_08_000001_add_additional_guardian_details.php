<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table): void {
            $table->string('additional_guardian_name')->nullable();
            $table->string('additional_guardian_relationship', 100)->nullable();
            $table->string('additional_guardian_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table): void {
            $table->dropColumn(['additional_guardian_name', 'additional_guardian_relationship', 'additional_guardian_phone']);
        });
    }
};
