<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id')->unique();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('endpoint_hash', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->uuid('device_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
        Schema::dropIfExists('fcm_tokens');
    }
};
