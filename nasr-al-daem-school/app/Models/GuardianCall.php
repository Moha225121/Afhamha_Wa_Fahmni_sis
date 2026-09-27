<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id','supervisor_id','student_note_id','category','reason','details','guardian_visible_details','status','requested_date','meeting_date','guardian_response','resolved_at'])]
class GuardianCall extends Model
{
    protected function casts(): array { return ['requested_date'=>'date','meeting_date'=>'datetime','resolved_at'=>'datetime']; }
    public function student(): BelongsTo { return $this->belongsTo(Student::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(User::class, 'supervisor_id'); }
    public function note(): BelongsTo { return $this->belongsTo(StudentNote::class, 'student_note_id'); }
}
