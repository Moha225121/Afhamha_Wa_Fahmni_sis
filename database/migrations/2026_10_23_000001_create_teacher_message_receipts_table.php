<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_message_receipts', function (Blueprint $table): void {
            // The notification is inserted by the worker after this queued receipt is prepared.
            $table->uuid('notification_id')->primary();
            $table->foreignId('teacher_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('dispatch_id');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['dispatch_id', 'recipient_user_id']);
            $table->index(['teacher_user_id', 'dispatch_id']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->longText('data')->change());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_message_receipts');
        // Keep the widened notification body column to avoid truncating existing messages.
    }
};
