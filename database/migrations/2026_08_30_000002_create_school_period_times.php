<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('school_period_times', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedTinyInteger('period_number');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->unique(['day_of_week','period_number']);
        });
        Schema::table('schedules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('period_number')->nullable()->after('day_of_week');
        });
        $rows=[];
        foreach(range(0,4) as $day) foreach(range(1,12) as $number){$start=Carbon::createFromTime(8,30)->addMinutes(($number-1)*45);$rows[]=['day_of_week'=>$day,'period_number'=>$number,'starts_at'=>$start->format('H:i:s'),'ends_at'=>$start->copy()->addMinutes(45)->format('H:i:s'),'created_at'=>now(),'updated_at'=>now()];}
        DB::table('school_period_times')->insert($rows);
    }
    public function down(): void
    {
        Schema::table('schedules', fn (Blueprint $table) => $table->dropColumn('period_number'));
        Schema::dropIfExists('school_period_times');
    }
};
