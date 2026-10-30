<?php

namespace App\Services;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StaffAccess
{
    public const MODULES = [
        'students' => ['label' => 'شؤون الطلاب', 'permissions' => ['students.view', 'students.create', 'students.update', 'student-guardians.manage'], 'description' => 'بيانات الطلاب وروابط أولياء الأمور.'],
        'academic' => ['label' => 'المتابعة الأكاديمية', 'permissions' => ['academic.view'], 'description' => 'درجات الطلاب ونتائج الاختبارات.'],
        'attendance' => ['label' => 'متابعة الحضور', 'permissions' => ['attendance.view'], 'description' => 'الحضور والغياب والتأخير.'],
        'assignments' => ['label' => 'متابعة الواجبات', 'permissions' => ['assignments.view'], 'description' => 'الواجبات المنشورة ومواعيد التسليم.'],
        'teachers' => ['label' => 'متابعة المعلمين', 'permissions' => ['teacher-monitoring.view'], 'description' => 'تكليفات المعلمين والدروس والواجبات.'],
        'notifications' => ['label' => 'إشعارات أولياء الأمور', 'permissions' => ['parent-notifications.send'], 'description' => 'التواصل مع أولياء الأمور المرتبطين بالطلاب.'],
        'calls' => ['label' => 'استدعاءات أولياء الأمور', 'permissions' => ['guardian-calls.manage'], 'description' => 'طلب حضور ولي الأمر ومتابعة الاستدعاء.'],
        'announcements' => ['label' => 'الإعلانات والاتصالات', 'permissions' => ['announcements.create', 'announcements.publish', 'announcements.broadcast'], 'description' => 'إعداد الإعلانات ونشرها وإرسال إشعاراتها.'],
        'activities' => ['label' => 'الأنشطة المدرسية', 'permissions' => ['activities.manage'], 'description' => 'تنظيم الأنشطة ومتابعة مواعيدها.'],
    ];

    public function modules(User $user): array
    {
        return array_filter(self::MODULES, fn ($module) => collect($module['permissions'])->contains(fn ($permission) => $user->hasPermission($permission)));
    }

    /** Each branch keeps assignment and role-permission scopes together. */
    private function branches(User $user, string $permission): array
    {
        if (! $user->hasPermission($permission)) {
            return [];
        }
        if ($user->isAdmin() || in_array($permission, config('permissions.roles.'.$user->role, []), true)) {
            return [[]];
        }

        return $user->staffRoleAssignments()->where('active', true)->whereHas('role', fn ($q) => $q->where('active', true))
            ->with('role.permissions')->get()->flatMap(function ($assignment) use ($permission) {
                return $assignment->role->permissions->where('permission', $permission)
                    ->map(fn ($rule) => [$assignment->scope ?: [], $rule->scope ?: []]);
            })->all();
    }

    public function allows(User $user, string $permission, ?int $classroomId = null, ?int $studentId = null): bool
    {
        foreach ($this->branches($user, $permission) as $scopes) {
            $allowed = true;
            foreach ($scopes as $scope) {
                if (! empty($scope['classroom_ids']) && ($classroomId === null || ! in_array($classroomId, $scope['classroom_ids']))) {
                    $allowed = false;
                }
                if (! empty($scope['student_ids']) && ($studentId === null || ! in_array($studentId, $scope['student_ids']))) {
                    $allowed = false;
                }
            }
            if ($allowed) {
                return true;
            }
        }

        return false;
    }

    public function constrain(Builder $query, User $user, string $permission, string $classroomColumn = 'classroom_id', ?string $studentColumn = null): Builder
    {
        $branches = $this->branches($user, $permission);
        if (! $branches) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($outer) use ($branches, $classroomColumn, $studentColumn) {
            foreach ($branches as $scopes) {
                $outer->orWhere(function ($branch) use ($scopes, $classroomColumn, $studentColumn) {
                    $branch->whereRaw('1 = 1');
                    foreach ($scopes as $scope) {
                        if (! empty($scope['classroom_ids'])) {
                            $branch->whereIn($classroomColumn, $scope['classroom_ids']);
                        }
                        if (! empty($scope['student_ids'])) {
                            $branch->whereIn($studentColumn ?: $classroomColumn, $studentColumn ? $scope['student_ids'] : Student::whereIn('id', $scope['student_ids'])->select('classroom_id'));
                        }
                    }
                });
            }
        });
    }

    public function students(User $user, string $permission): Builder
    {
        return $this->constrain(Student::query(), $user, $permission, 'students.classroom_id', 'students.id');
    }

    /** A shared lesson/homework is in scope when any explicitly targeted class is allowed. */
    public function learningResources(Builder $query, User $user, string $permission): Builder
    {
        if ($this->allows($user, $permission)) {
            return $query;
        }
        $ids = $this->classrooms($user, $permission)->modelKeys();
        $column = $query->getModel()->qualifyColumn('classroom_id');

        return $query->where(fn (Builder $targets) => $targets->whereIn($column, $ids)
            ->orWhereHas('classrooms', fn (Builder $rooms) => $rooms->whereIn('classrooms.id', $ids)));
    }

    public function classrooms(User $user, string $permission): Collection
    {
        return $this->constrain(Classroom::query(), $user, $permission, 'classrooms.id')->orderBy('name')->orderBy('section')->get();
    }
}
