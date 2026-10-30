<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedInteger('chunk_size');
            $table->text('hashes');
            $table->string('status')->default('uploading');
            $table->string('file_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lesson_uploads')) {
            return;
        }
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Automatic lesson upload rollback is unsupported on this database. Preserve the table and back up upload metadata and private files.');
        }
        DB::transaction(function () use ($driver): void {
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE lesson_uploads IN ACCESS EXCLUSIVE MODE');
            } else {
                DB::table('lesson_uploads')->whereRaw('1 = 0')->update(['status' => 'uploading']);
            }
            if (DB::table('lesson_uploads')->exists()) {
                throw new RuntimeException('Refusing to drop lesson_uploads while upload records exist. Roll back application code only; preserve and back up the table and private files.');
            }
            Schema::drop('lesson_uploads');
        });
    }
};
