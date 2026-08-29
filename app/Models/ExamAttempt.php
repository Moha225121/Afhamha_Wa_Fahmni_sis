<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    protected $fillable = ['exam_id', 'student_id', 'attempt_number', 'started_at', 'expires_at', 'submitted_at', 'graded_at', 'status', 'score', 'maximum_score', 'percentage'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime', 'score' => 'decimal:2', 'maximum_score' => 'decimal:2', 'percentage' => 'decimal:2'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class)->orderBy('id');
    }
}
