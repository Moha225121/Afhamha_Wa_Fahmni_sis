<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assignment_attachments', 'disk')) {
            Schema::table('assignment_attachments', function (Blueprint $table): void {
                $table->string('disk', 50)->nullable();
            });
        }

        if (! Schema::hasColumn('assignment_attachments', 'sort_order')) {
            Schema::table('assignment_attachments', function (Blueprint $table): void {
                $table->unsignedInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasColumn('assignment_submission_attachments', 'disk')) {
            Schema::table('assignment_submission_attachments', function (Blueprint $table): void {
                $table->string('disk', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assignment_submission_attachments', 'disk')) {
            Schema::table('assignment_submission_attachments', function (Blueprint $table): void {
                $table->dropColumn('disk');
            });
        }

        if (Schema::hasColumn('assignment_attachments', 'sort_order')) {
            Schema::table('assignment_attachments', function (Blueprint $table): void {
                $table->dropColumn('sort_order');
            });
        }

        if (Schema::hasColumn('assignment_attachments', 'disk')) {
            Schema::table('assignment_attachments', function (Blueprint $table): void {
                $table->dropColumn('disk');
            });
        }
    }
};
