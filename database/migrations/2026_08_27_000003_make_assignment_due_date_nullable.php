<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('assignments', 'due_date')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->date('due_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assignments', 'due_date')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->date('due_date')->nullable(false)->change();
            });
        }
    }
};
