<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'status', 'phone', 'avatar_path', 'last_login_at', 'first_name_en', 'last_name_en', 'school_email_generated', 'financial_permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return ['financial_permissions' => 'array', 'school_email_generated' => 'boolean', 'email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime'];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' && $this->status === 'active';
    }

    public function isParent(): bool
    {
        return $this->role === 'parent' && $this->status === 'active';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student' && $this->status === 'active';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher' && $this->status === 'active';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor' && $this->status === 'active';
    }

    public function supervisedClassrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'supervisor_class_assignments', 'supervisor_id', 'classroom_id')->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->isAdmin()) return true;
        if (in_array($permission, ['finance.view', 'finance.pay', 'finance.reports'], true)) {
            return $this->role === 'financial_officer'
                || ($this->role === 'supervisor' && in_array($permission, $this->financial_permissions ?? [], true));
        }
        if ($this->staffRoleAssignments()->where('active', true)->whereHas('role', fn ($q) => $q->where('active', true)->whereHas('permissions', fn ($p) => $p->where('permission', $permission)))->exists()) return true;
        $p = config('permissions.roles.'.$this->role, []);
        if (empty($p)) {
            return false;
        }

        return in_array('*', $p, true) || in_array($permission, $p, true);
    }

    public function staffRoles(): BelongsToMany { return $this->belongsToMany(StaffRole::class, 'staff_role_assignments')->withPivot(['scope','active'])->withTimestamps(); }
    public function staffRoleAssignments(): HasMany { return $this->hasMany(\App\Models\StaffRoleAssignment::class); }
    public function hasScopedPermission(string $permission, ?int $classroomId = null, ?int $studentId = null): bool
    {
        return app(\App\Services\StaffAccess::class)->allows($this, $permission, $classroomId, $studentId);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function fcmDevices(): HasMany
    {
        return $this->hasMany(FcmDevice::class);
    }
}
