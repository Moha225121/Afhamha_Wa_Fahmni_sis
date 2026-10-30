<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('client_request_id')->nullable();
            $table->unique(['sender_id', 'client_request_id']);
        });
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('messages', fn (Blueprint $table) => $table->longText('body')->change());
        }
        Schema::create('conversation_message_receipts', function (Blueprint $table): void {
            $table->uuid('notification_id')->primary();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'recipient_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_receipts');
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique(['sender_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
        // Keep the wider body column: rolling back must never truncate messages.
    }
};
