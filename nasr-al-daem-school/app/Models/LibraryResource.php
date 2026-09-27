<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'category', 'subject_id', 'classroom_id', 'file_path', 'disk', 'is_public', 'status', 'created_by'])]
class LibraryResource extends Model
{
    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function scopeVisibleTo(Builder $query, Student $student): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $classroomScope) use ($student): void {
                $classroomScope->whereNull('classroom_id');

                if ($student->classroom_id) {
                    $classroomScope->orWhere('classroom_id', $student->classroom_id);
                }
            })
            ->where(function (Builder $subjectScope) use ($student): void {
                $subjectScope->whereNull('subject_id');

                if ($student->classroom_id) {
                    $subjectScope->orWhereHas('subject', function (Builder $subjects) use ($student): void {
                        $subjects
                            ->where('status', 'active')
                            ->whereHas('classrooms', fn (Builder $classrooms) => $classrooms->whereKey($student->classroom_id));
                    });
                }
            })
            ->where(function (Builder $audienceScope): void {
                $audienceScope
                    ->where('is_public', true)
                    ->orWhereNotNull('classroom_id')
                    ->orWhereNotNull('subject_id');
            });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
