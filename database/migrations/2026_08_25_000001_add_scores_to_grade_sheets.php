<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_sheets', function (Blueprint $table) {
            $table->json('scores')->nullable()->after('sheet_data');
        });
    }

    public function down(): void
    {
        Schema::table('grade_sheets', function (Blueprint $table) {
            $table->dropColumn('scores');
        });
    }
};
