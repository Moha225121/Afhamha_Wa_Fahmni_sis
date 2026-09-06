<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->string('first_name_en', 80)->nullable();
            $t->string('last_name_en', 100)->nullable();
            $t->boolean('school_email_generated')->default(false);
            $t->json('financial_permissions')->nullable();
        });
        Schema::table('teachers', fn (Blueprint $t) => $t->string('teacher_number', 100)->nullable()->unique());
        Schema::create('account_number_settings', function (Blueprint $t): void {
            $t->id();
            $t->json('configuration');
            $t->timestamps();
        });
        $settings = config('school_accounts');
        $settings['school_name'] = DB::table('settings')->where('key', 'school_name')->value('value') ?: $settings['school_name'];
        DB::table('account_number_settings')->insert(['id' => 1, 'configuration' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('number_sequences', function (Blueprint $t): void {
            $t->string('scope')->primary();
            $t->unsignedBigInteger('next_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('account_number_settings');
        Schema::table('teachers', function (Blueprint $t): void {
            $t->dropUnique(['teacher_number']);
            $t->dropColumn('teacher_number');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['first_name_en', 'last_name_en', 'school_email_generated', 'financial_permissions']));
    }
};
