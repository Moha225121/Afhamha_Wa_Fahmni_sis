<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'content', 'audience', 'classroom_id', 'published_at', 'expires_at', 'status', 'created_by'])] class Announcement extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function scopeCurrentlyPublished(Builder $query): void
    {
        $query->where('status', 'published')
            ->where(fn (Builder $dates) => $dates->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $dates) => $dates->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeVisibleToStudent(Builder $query, Student $student): void
    {
        $query->currentlyPublished()->where(function (Builder $audience) use ($student): void {
            $audience->whereIn('audience', ['all', 'students'])
                ->when($student->classroom_id, fn (Builder $classrooms) => $classrooms->orWhere(
                    fn (Builder $classroom) => $classroom->where('audience', 'classroom')->where('classroom_id', $student->classroom_id),
                ));
        });
    }
}
