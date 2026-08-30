<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id','classroom_id','date','status','arrival_time','late_minutes','excuse_reason','excuse_document','notes','recorded_by','updated_by'])]
class Attendance extends Model
{
    protected $table = 'attendance_records';
    protected function casts(): array { return ['date'=>'date','status'=>AttendanceStatus::class]; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function updater(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
