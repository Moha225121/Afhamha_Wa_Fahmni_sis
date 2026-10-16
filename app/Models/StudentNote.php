<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['student_id', 'supervisor_id', 'type', 'title', 'content', 'visibility', 'requires_guardian_call'])]
class StudentNote extends Model
{
    protected function casts(): array
    {
        return ['requires_guardian_call' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function guardianCalls(): HasMany
    {
        return $this->hasMany(GuardianCall::class);
    }

    public function typeLabel(): string
    {
        return ['general' => 'عامة', 'health' => 'صحية', 'psychological' => 'نفسية', 'behavioral' => 'سلوكية', 'administrative' => 'إدارية'][$this->type] ?? 'عامة';
    }
}
