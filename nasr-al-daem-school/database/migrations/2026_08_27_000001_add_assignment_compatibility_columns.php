<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('assignments', 'instructions')) {
                $table->text('instructions')->nullable();
            }

            if (! Schema::hasColumn('assignments', 'due_at')) {
                $table->dateTime('due_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('assignments', 'instructions')) {
                $table->dropColumn('instructions');
            }

            if (Schema::hasColumn('assignments', 'due_at')) {
                $table->dropColumn('due_at');
            }
        });
    }
};
