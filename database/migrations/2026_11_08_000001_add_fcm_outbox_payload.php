<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_notification_events', function (Blueprint $table): void {
            $table->json('payload')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->index(['queued_at', 'sent_at'], 'fcm_outbox_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fcm_notification_events', function (Blueprint $table): void {
            $table->dropIndex('fcm_outbox_pending_idx');
            $table->dropColumn(['payload', 'queued_at']);
        });
    }
};
