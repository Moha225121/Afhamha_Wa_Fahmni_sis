<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRolePermission extends Model
{
    protected $fillable = ['staff_role_id', 'permission', 'scope'];

    protected $casts = ['scope' => 'array'];

    public function role()
    {
        return $this->belongsTo(StaffRole::class, 'staff_role_id');
    }
}
