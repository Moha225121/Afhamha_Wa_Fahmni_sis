<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StaffRoleAssignment extends Model {
    protected $fillable=['user_id','staff_role_id','scope','active']; protected $casts=['scope'=>'array','active'=>'boolean'];
    public function role(){return $this->belongsTo(StaffRole::class,'staff_role_id');}
    public function permissions(){return $this->hasManyThrough(StaffRolePermission::class,StaffRole::class,'id','staff_role_id','staff_role_id','id');}
    public function allows(?int $classroomId, ?int $studentId): bool { $s=$this->scope ?: []; return (!$classroomId || empty($s['classroom_ids']) || in_array($classroomId,$s['classroom_ids'])) && (!$studentId || empty($s['student_ids']) || in_array($studentId,$s['student_ids'])); }
}
