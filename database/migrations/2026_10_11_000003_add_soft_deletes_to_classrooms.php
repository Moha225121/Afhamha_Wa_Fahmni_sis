<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('classrooms', 'deleted_at')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('classrooms', 'deleted_at')) {
            return;
        }

        Schema::table('classrooms', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
