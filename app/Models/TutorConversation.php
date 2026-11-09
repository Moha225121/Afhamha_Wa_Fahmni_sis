<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['student_id', 'teacher_user_id', 'creation_request_id', 'title'])]
class TutorConversation extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $conversation): void {
            if (($conversation->student_id === null) === ($conversation->teacher_user_id === null)) {
                throw new \LogicException('A tutor conversation must have exactly one student or teacher owner.');
            }
        });
    }

    public function teacherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TutorMessage::class);
    }
}
