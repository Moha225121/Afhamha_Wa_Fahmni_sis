<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class StaffRole extends Model {
    protected $fillable=['name','description','active']; protected $casts=['active'=>'boolean'];
    public function permissions(){return $this->hasMany(StaffRolePermission::class);}
    public function users(): BelongsToMany { return $this->belongsToMany(User::class,'staff_role_assignments')->withPivot(['scope','active'])->withTimestamps(); }
}
