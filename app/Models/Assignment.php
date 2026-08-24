<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = ['title', 'description', 'subject_id', 'classroom_id', 'teacher_id', 'due_at', 'attachment_path', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssignmentAttachment::class)->orderBy('sort_order')->orderBy('id');
    }
}
