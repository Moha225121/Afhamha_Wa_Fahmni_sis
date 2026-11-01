<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_notification_events');
        Schema::dropIfExists('fcm_devices');
    }
};
