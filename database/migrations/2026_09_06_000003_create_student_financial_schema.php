<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        foreach (['الرسوم الدراسية', 'التسجيل', 'الكتب', 'المواصلات', 'الأنشطة', 'الامتحانات', 'رسوم أخرى'] as $name) {
            DB::table('fee_types')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        foreach (['نقدي', 'تحويل مصرفي', 'بطاقة', 'شيك'] as $name) {
            DB::table('payment_methods')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('fee_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('amount_millimes');
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->string('grade')->nullable();
            $t->foreignId('classroom_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('due_date');
            $t->boolean('required')->default(true);
            $t->boolean('active')->default(true);
            $t->json('installment_plan');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('student_fees', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('fee_template_id')->constrained()->restrictOnDelete();
            $t->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unsignedBigInteger('amount_millimes');
            $t->timestamps();
            $t->unique(['student_id', 'fee_template_id']);
        });
        Schema::create('student_installments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_fee_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unsignedBigInteger('amount_millimes');
            $t->unsignedBigInteger('discount_millimes')->default(0);
            $t->date('due_date')->index();
            $t->timestamps();
        });
        Schema::create('student_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_installment_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_millimes');
            $t->date('paid_on')->index();
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('posted')->index();
            $t->uuid('request_key')->unique();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('discounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_fee_id')->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->string('value', 40);
            $t->unsignedBigInteger('amount_millimes');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_payment_id')->unique()->constrained()->restrictOnDelete();
            $t->string('number', 60)->unique();
            $t->json('snapshot');
            $t->timestamps();
        });
        Schema::create('financial_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_fee_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('discount_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->bigInteger('amount_millimes');
            $t->text('description');
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('financial_notifications', function (Blueprint $t): void {
            $t->id();
            $t->string('event_key')->unique();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['financial_notifications', 'financial_transactions', 'receipts', 'discounts', 'student_payments', 'student_installments', 'student_fees', 'fee_templates', 'payment_methods', 'fee_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
