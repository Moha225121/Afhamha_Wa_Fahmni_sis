<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status'])] class Student extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student');
    }

    public function tutorConversations(): HasMany
    {
        return $this->hasMany(TutorConversation::class);
    }

    public function assignmentSubmissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function attendances(): HasMany { return $this->hasMany(Attendance::class); }
    public function studentNotes(): HasMany { return $this->hasMany(StudentNote::class); }
    public function guardianCalls(): HasMany { return $this->hasMany(GuardianCall::class); }
}
