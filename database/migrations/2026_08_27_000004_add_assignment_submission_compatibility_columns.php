<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('assignment_submissions', 'status')) {
                $table->string('status', 20)->default('submitted')->index();
            }

            if (! Schema::hasColumn('assignment_submissions', 'notes')) {
                $table->text('notes')->nullable();
            }

            if (! Schema::hasColumn('assignment_submissions', 'graded_at')) {
                $table->timestamp('graded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table): void {
            foreach (['status', 'notes', 'graded_at'] as $column) {
                if (Schema::hasColumn('assignment_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
