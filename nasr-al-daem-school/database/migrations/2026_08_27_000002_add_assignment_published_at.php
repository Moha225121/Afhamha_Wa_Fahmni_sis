<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assignments', 'published_at')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->timestamp('published_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assignments', 'published_at')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->dropColumn('published_at');
            });
        }
    }
};
