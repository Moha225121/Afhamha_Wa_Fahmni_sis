<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    protected $fillable = ['assignment_id', 'student_id', 'file_path', 'original_name', 'mime_type', 'file_size', 'notes', 'submitted_at', 'status'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
