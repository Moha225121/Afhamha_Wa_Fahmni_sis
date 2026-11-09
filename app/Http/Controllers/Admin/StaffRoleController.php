<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Models\StaffRole; use App\Models\User; use App\Services\AuditService; use App\Services\AccountPasswordService; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Illuminate\Validation\Rule;
class StaffRoleController extends Controller {
 public function index(){return view('admin.operations.roles', ['roles'=>StaffRole::with('permissions')->latest()->get(),'catalog'=>config('permissions.catalog')]);}
 private function roleData(Request $request, ?StaffRole $role = null): array
 {
     return $request->validate([
         'name' => ['required', 'string', 'max:120', Rule::unique('staff_roles', 'name')->ignore($role?->id)],
         'description' => 'nullable|string|max:1000',
         'permissions' => 'array',
         'permissions.*' => ['string', Rule::in(config('permissions.catalog', []))],
         'scope' => 'nullable|array:classroom_ids,student_ids',
         'scope.classroom_ids' => 'array',
         'scope.classroom_ids.*' => 'integer|exists:classrooms,id',
         'scope.student_ids' => 'array',
         'scope.student_ids.*' => 'integer|exists:students,id',
     ]);
 }
 public function store(Request $r)
 {
     $d = $this->roleData($r);
     return DB::transaction(function () use ($d) {
         $role = StaffRole::create(['name' => $d['name'], 'description' => $d['description'] ?? null]);
         foreach (array_unique($d['permissions'] ?? []) as $permission) {
             $role->permissions()->create(['permission' => $permission, 'scope' => $d['scope'] ?? null]);
         }
         AuditService::record('created', 'staff_roles', $role);
         return back()->with('success', 'تم إنشاء الدور.');
     });
 }
 public function update(Request $r, StaffRole $staffRole)
 {
     $d = $this->roleData($r, $staffRole);
     if ($staffRole->isProtectedFromDeletion() && $d['name'] !== $staffRole->name) {
         throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'لا يمكن تغيير اسم دور محمي. يمكن تعديل صلاحياته المسموح بها.']);
     }
     DB::transaction(function () use ($d, $staffRole) {
         $staffRole = StaffRole::whereKey($staffRole->id)->lockForUpdate()->firstOrFail();
         $existingScopes = $staffRole->permissions()->get()->keyBy('permission');
         $staffRole->update(['name' => $d['name'], 'description' => $d['description'] ?? null]);
         $permissions = array_unique($d['permissions'] ?? []);
         $staffRole->permissions()->whereNotIn('permission', $permissions)->delete();
         foreach ($permissions as $permission) {
             // Editing labels/permissions must not silently broaden a saved scope.
             $scope = array_key_exists('scope', $d) ? $d['scope'] : $existingScopes->get($permission)?->scope;
             $staffRole->permissions()->updateOrCreate(['permission' => $permission], ['scope' => $scope]);
         }
         AuditService::record('updated', 'staff_roles', $staffRole);
     });
     return back()->with('success', 'تم تحديث الدور.');
 }
 public function destroy(StaffRole $staffRole)
 {
     DB::transaction(function () use ($staffRole): void {
         if (DB::connection()->getDriverName() === 'sqlite') {
             DB::table('staff_roles')->where('id', $staffRole->id)->update(['id' => $staffRole->id]);
         }
         $role = StaffRole::whereKey($staffRole->id)->lockForUpdate()->firstOrFail();
         if ($role->isProtectedFromDeletion()) {
             throw \Illuminate\Validation\ValidationException::withMessages(['role' => 'هذا دور محمي ولا يمكن حذفه.']);
         }
         if ($role->users()->exists()) {
             throw \Illuminate\Validation\ValidationException::withMessages(['role' => 'لا يمكن حذف دور مسند إلى حسابات، بما فيها الإسنادات غير النشطة.']);
         }
         $old = $role->getAttributes() + ['permissions' => $role->permissions()->get(['permission', 'scope'])->toArray()];
         $role->delete();
         AuditService::record('deleted', 'staff_roles', $role, $old);
     });
     return redirect()->route('admin.roles.index')->with('success', 'تم حذف الدور المخصص غير المسند.');
 }
 public function assign(Request $r, User $user)
 {
     abort_if($user->is($r->user()), 422, 'لا يمكنك تعديل صلاحيات حسابك.');
     $d = $r->validate(['role_ids' => 'array', 'role_ids.*' => 'integer|exists:staff_roles,id', 'scope' => 'nullable|array:classroom_ids,student_ids', 'scope.classroom_ids' => 'array', 'scope.classroom_ids.*' => 'integer|exists:classrooms,id', 'scope.student_ids' => 'array', 'scope.student_ids.*' => 'integer|exists:students,id']);
     DB::transaction(function () use ($d, $user): void {
         $existing = $user->staffRoles()->get()->keyBy('id');
         $user->staffRoles()->sync(collect($d['role_ids'] ?? [])->mapWithKeys(function ($id) use ($d, $existing) {
             $scope = array_key_exists('scope', $d)
                 ? (isset($d['scope']) ? json_encode($d['scope']) : null)
                 : $existing->get($id)?->pivot->scope;
             return [$id => ['scope' => $scope, 'active' => true]];
         })->all());
         AuditService::record('updated', 'staff_role_assignments', $user);
     });
     return back()->with('success', 'تم تحديث صلاحيات الموظف.');
 }
 public function storeStaff(Request $r, AccountPasswordService $passwords){$d=$r->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users,email','phone'=>'nullable|string|max:30','password'=>'nullable|string|min:8|confirmed','role_ids'=>'required|array|min:1','role_ids.*'=>'integer|exists:staff_roles,id','classroom_ids'=>'nullable|array','classroom_ids.*'=>'integer|exists:classrooms,id']);return DB::transaction(function()use($d,$passwords){$u=User::create(['name'=>$d['name'],'email'=>$d['email'],'phone'=>$d['phone']??null,'password'=>$passwords->forNewAccount($d['password']??null),'role'=>'staff','status'=>'active']);$u->staffRoles()->sync(collect($d['role_ids'])->mapWithKeys(fn($id)=>[$id=>['scope'=>json_encode(['classroom_ids'=>$d['classroom_ids']??[]]),'active'=>true]])->all());AuditService::record('created','staff_users',$u);return back()->with('success','تم إنشاء حساب الموظف وربط صلاحياته.');});}
}
