<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('grade_sheets', 'column_scores')) {
            Schema::table('grade_sheets', function (Blueprint $table): void {
                $table->json('column_scores')->nullable()->after('scores');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('grade_sheets', 'column_scores')) {
            Schema::table('grade_sheets', function (Blueprint $table): void {
                $table->dropColumn('column_scores');
            });
        }
    }
};
