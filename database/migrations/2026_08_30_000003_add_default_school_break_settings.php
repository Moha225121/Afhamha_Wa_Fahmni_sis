<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['break_after_period'=>'3','break_duration_minutes'=>'30'] as $key=>$value) DB::table('settings')->insertOrIgnore(['key'=>$key,'value'=>$value,'group'=>'school','created_at'=>now(),'updated_at'=>now()]);
        foreach (range(0,4) as $day) foreach (range(4,12) as $period) {
            $expected=Carbon::createFromTime(8,30)->addMinutes(($period-1)*45);
            DB::table('school_period_times')->where('day_of_week',$day)->where('period_number',$period)->where('starts_at',$expected->format('H:i:s'))->where('ends_at',$expected->copy()->addMinutes(45)->format('H:i:s'))->update(['starts_at'=>$expected->copy()->addMinutes(30)->format('H:i:s'),'ends_at'=>$expected->copy()->addMinutes(75)->format('H:i:s'),'updated_at'=>now()]);
        }
    }
    public function down(): void { DB::table('settings')->whereIn('key',['break_after_period','break_duration_minutes'])->delete(); }
};
