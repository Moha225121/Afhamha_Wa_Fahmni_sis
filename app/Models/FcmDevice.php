<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'token', 'token_hash', 'device_label', 'last_seen_at'])]
#[Hidden(['token', 'token_hash'])]
class FcmDevice extends Model
{
    public static function canReceive(User $user): bool
    {
        return match ($user->role) {
            'admin' => $user->isAdmin(),
            'teacher' => $user->isTeacher() && $user->teacher?->status === 'active',
            'student' => $user->isStudent() && $user->student?->status === 'active',
            'parent' => $user->isParent() && $user->guardian?->status === 'active',
            default => false,
        };
    }

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
