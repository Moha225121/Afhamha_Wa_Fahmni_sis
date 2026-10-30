<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['teacher_id', 'classroom_id', 'subject_id', 'unit_id', 'title', 'content', 'status', 'published_at', 'position', 'submission_key'])]
class Lesson extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeForClassroom(Builder $query, int $classroomId): Builder
    {
        return $query->where(fn ($query) => $query
            ->where('classroom_id', $classroomId)
            ->orWhereNull('classroom_id')
            ->orWhereHas('classrooms', fn ($classrooms) => $classrooms->whereKey($classroomId)));
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'lesson_classroom');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    /** One historical record per student who opened this lesson. */
    public function views(): HasMany
    {
        return $this->hasMany(LessonView::class);
    }
}
