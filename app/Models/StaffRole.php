<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class StaffRole extends Model {
    protected $fillable=['name','description','active']; protected $casts=['active'=>'boolean'];
    public function permissions(){return $this->hasMany(StaffRolePermission::class);}
    public function users(): BelongsToMany { return $this->belongsToMany(User::class,'staff_role_assignments')->withPivot(['scope','active'])->withTimestamps(); }
    public function isProtectedFromDeletion(): bool
    {
        // Seed roles have no application creation audit. Requiring provenance
        // protects them even if an older version allowed their names to change.
        return array_key_exists($this->name, config('permissions.staff_role_labels', []))
            || ! AuditLog::where('module', 'staff_roles')->where('action', 'created')
                ->where('record_id', $this->id)->exists();
    }
}
