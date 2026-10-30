<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Assignment extends Model
{
    public const PUBLISHED_STATUSES = ['published', 'active'];

    public function isPublished(): bool
    {
        // "active" is the historical Teacher Portal alias for a published assignment.
        return in_array($this->status, self::PUBLISHED_STATUSES, true)
            && (! $this->published_at || $this->published_at->lte(now()));
    }

    protected $fillable = [
        'classroom_id', 'subject_id', 'teacher_id', 'title', 'instructions', 'description', 'due_at', 'due_date', 'attachment_path', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'due_date' => 'date', 'published_at' => 'datetime'];
    }

    public function isAvailableToStudent(Student $student): bool
    {
        if (! $student->classroom_id
            || ! in_array($this->status, ['published', 'active'], true)
            || ($this->published_at && $this->published_at->gt(now()))) {
            return false;
        }

        return (int) $this->classroom_id === (int) $student->classroom_id
            || (Schema::hasTable('assignment_classroom')
                && $this->classrooms()->whereKey($student->classroom_id)->exists());
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'assignment_classroom');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssignmentAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
