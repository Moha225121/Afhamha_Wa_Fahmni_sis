<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('device_label', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('fcm_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 150);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
        });

        Schema::create('fcm_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('fcm_notification_events')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('fcm_devices')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'device_id']);
        });

        Schema::create('fcm_schedule_cursors', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->timestamp('checked_through');
        });
        DB::table('fcm_schedule_cursors')->insert(['name' => 'announcements', 'checked_through' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_schedule_cursors');
        Schema::dropIfExists('fcm_notification_deliveries');
        Schema::dropIfExists('fcm_notification_events');
        Schema::dropIfExists('fcm_devices');
    }
};
