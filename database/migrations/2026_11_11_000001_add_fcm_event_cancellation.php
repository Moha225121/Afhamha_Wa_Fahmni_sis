<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_notification_events', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 80)->nullable();
        });
    }

    public function down(): void
    {
        // Removing cancellation state could replay an event on an older worker.
        // Keep the additive schema during code rollback once cancellation exists.
        if (\Illuminate\Support\Facades\DB::table('fcm_notification_events')->whereNotNull('cancelled_at')->exists()) {
            throw new RuntimeException('FCM cancellation history must be preserved; retain this additive migration during rollback.');
        }
        Schema::table('fcm_notification_events', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
