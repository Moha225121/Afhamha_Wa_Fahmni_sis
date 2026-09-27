<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->string('file_path')->nullable()->change();
            $table->string('original_name')->nullable()->change();
            $table->string('mime_type', 150)->nullable()->change();
            $table->unsignedBigInteger('file_size')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->string('file_path')->nullable(false)->change();
            $table->string('original_name')->nullable(false)->change();
            $table->string('mime_type', 150)->nullable(false)->change();
            $table->unsignedBigInteger('file_size')->nullable(false)->change();
        });
    }
};
