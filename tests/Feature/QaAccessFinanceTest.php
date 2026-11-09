<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\StaffRole;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SchoolAccountService;
use App\Services\StudentFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaAccessFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function student(): Student
    {
        $year = AcademicYear::create(['name' => 'QA-2026-2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $class = Classroom::create(['name' => 'QA Grade', 'stage' => 'Basic', 'section' => 'A', 'academic_year_id' => $year->id]);

        return Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'student_number' => 'QA-S1', 'classroom_id' => $class->id, 'status' => 'active']);
    }

    public function test_permission_labels_match_inputs_and_saved_role_can_be_edited_and_revoked(): void
    {
        $this->get(route('admin.roles.index'))->assertOk()->assertSeeText('عرض بيانات الطلاب')->assertSee('for="permission-create-students-view"', false);
        $this->post(route('admin.roles.store'), ['name' => 'QA scoped reader', 'permissions' => ['students.view']])->assertSessionHasNoErrors();
        $role = StaffRole::where('name', 'QA scoped reader')->firstOrFail();
        $this->assertSame(['students.view'], $role->permissions->pluck('permission')->all());
        $this->get(route('admin.roles.index'))->assertSee('action="'.route('admin.roles.update', $role).'"', false);
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $this->post(route('admin.users.roles.assign', $staff), ['role_ids' => [$role->id]])->assertSessionHasNoErrors();
        $this->actingAs($staff)->get('/staff/students')->assertOk();
        $this->post('/staff/students', [])->assertForbidden();
        $this->actingAs($this->admin)->put(route('admin.roles.update', $role), ['name' => $role->name, 'permissions' => []])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('staff_role_permissions', ['staff_role_id' => $role->id]);
        $this->actingAs($staff->fresh())->get('/staff/students')->assertForbidden();
    }

    public function test_unknown_permissions_and_non_admin_role_mutations_are_rejected(): void
    {
        $this->post(route('admin.roles.store'), ['name' => 'QA invalid', 'permissions' => ['*', 'finance.superpower']])->assertSessionHasErrors('permissions.0');
        $this->assertDatabaseMissing('staff_roles', ['name' => 'QA invalid']);
        $this->actingAs(User::factory()->create(['role' => 'staff', 'status' => 'active']))->post(route('admin.roles.store'), ['name' => 'QA unauthorized', 'permissions' => ['students.view']])->assertForbidden();
    }

    public function test_duplicate_role_name_returns_validation_error_and_keeps_original_permissions(): void
    {
        $payload = ['name' => 'QA duplicate', 'permissions' => ['students.view']];
        $this->post(route('admin.roles.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('admin.roles.store'), $payload)->assertSessionHasErrors('name');
        $this->assertSame(1, StaffRole::where('name', $payload['name'])->count());
    }

    public function test_scoped_permissions_survive_edit_and_enforce_student_ids_on_server(): void
    {
        $student = $this->student();
        $other = Student::create(['user_id' => User::factory()->create(['name' => 'QA Other Student', 'role' => 'student', 'status' => 'active'])->id, 'student_number' => 'QA-OUTSIDE', 'classroom_id' => $student->classroom_id, 'status' => 'active']);
        $this->post(route('admin.roles.store'), ['name' => 'QA scoped editor', 'permissions' => ['students.view', 'students.update'], 'scope' => ['student_ids' => [$student->id]]])->assertSessionHasNoErrors();
        $role = StaffRole::where('name', 'QA scoped editor')->firstOrFail();
        $this->put(route('admin.roles.update', $role), ['name' => 'QA renamed editor', 'permissions' => ['students.view', 'students.update']])->assertSessionHasNoErrors();
        foreach ($role->permissions()->get() as $permission) {
            $this->assertSame(['student_ids' => [$student->id]], $permission->scope);
        }
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $this->post(route('admin.users.roles.assign', $staff), ['role_ids' => [$role->id], 'scope' => ['classroom_ids' => [$student->classroom_id]]])->assertSessionHasNoErrors();
        $this->post(route('admin.users.roles.assign', $staff), ['role_ids' => [$role->id]])->assertSessionHasNoErrors();
        $this->assertSame(['classroom_ids' => [$student->classroom_id]], $staff->staffRoleAssignments()->firstOrFail()->scope);
        $this->actingAs($staff)->get('/staff/students')->assertOk()->assertSee($student->student_number)->assertDontSee($other->student_number);
        $this->get(route('staff.students.edit', $student))->assertOk();
        $this->get(route('staff.students.edit', $other))->assertForbidden();
        $staff->update(['status' => 'inactive']);
        $this->actingAs($staff->fresh())->get('/staff/students')->assertForbidden();
    }

    public function test_disabling_global_fee_preserves_all_financial_records_and_blocks_new_assignment(): void
    {
        $student = $this->student();
        $finance = app(StudentFinanceService::class);
        $template = $finance->createTemplate(['name' => 'QA global fee', 'amount' => '100', 'fee_type_id' => DB::table('fee_types')->value('id'), 'academic_year_id' => $student->classroom->academic_year_id, 'due_date' => '2026-12-01', 'required' => true, 'active' => true]);
        $this->post(route('finance.templates.apply', $template))->assertSessionHasNoErrors();
        $installment = DB::table('student_installments')->value('id');
        $this->post(route('finance.students.pay', $student), ['student_installment_id' => $installment, 'amount' => '25', 'paid_on' => now()->toDateString(), 'payment_method_id' => DB::table('payment_methods')->value('id'), 'request_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $tables = ['student_fees', 'student_installments', 'student_payments', 'receipts', 'financial_transactions'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        $this->patch(route('finance.templates.toggle', $template), ['active' => 0])->assertSessionHasNoErrors();
        $this->get(route('finance.templates'))->assertOk()->assertSeeText('غير نشطة');
        $this->assertDatabaseHas('fee_templates', ['id' => $template, 'active' => false]);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->post(route('finance.templates.apply', $template))->assertSessionHasErrors('fee');
        $this->assertSame(75000, $finance->statement($student)['totals']['remaining']);
        $this->actingAs(User::factory()->create(['role' => 'financial_officer', 'status' => 'active']))->patch(route('finance.templates.toggle', $template), ['active' => 1])->assertForbidden();
    }

    public function test_inactive_unassigned_teacher_is_soft_deleted_without_deleting_the_login_record(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'inactive']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'inactive']);
        $this->delete(route('admin.teachers.destroy', $teacher))->assertRedirect(route('admin.teachers.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'teachers', 'record_id' => $teacher->id, 'action' => 'deleted']);
    }

    public function test_teacher_with_assignments_is_not_deleted_and_keeps_links(): void
    {
        $student = $this->student();
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'inactive']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'inactive']);
        $subject = \App\Models\Subject::create(['name' => 'QA Science', 'code' => 'QA-SCI', 'stage' => 'Basic', 'status' => 'active']);
        $link = ['teacher_id' => $teacher->id, 'classroom_id' => $student->classroom_id, 'subject_id' => $subject->id];
        DB::table('teacher_assignments')->insert($link);
        $this->delete(route('admin.teachers.destroy', $teacher))->assertSessionHasErrors('teacher');
        $this->assertNotSoftDeleted('teachers', ['id' => $teacher->id]);
        $this->assertDatabaseHas('teacher_assignments', $link);
    }

    public function test_empty_guardian_profile_can_be_removed_and_existing_session_is_denied(): void
    {
        $user = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'status' => 'active']);
        $this->get(route('admin.parents.index'))->assertSee(route('admin.parents.destroy', $guardian), false)->assertSeeText('حذف');
        $this->delete(route('admin.parents.destroy', $guardian))->assertRedirect(route('admin.parents.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('guardians', ['id' => $guardian->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'inactive']);
        $this->actingAs($user->fresh())->get(route('parent.dashboard'))->assertForbidden();
    }

    public function test_guardian_with_student_links_is_not_deleted_and_keeps_all_relations(): void
    {
        $student = $this->student();
        $user = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'status' => 'active']);
        $guardian->students()->attach($student);
        $this->delete(route('admin.parents.destroy', $guardian))->assertSessionHasErrors('parent');
        $this->assertNotSoftDeleted('guardians', ['id' => $guardian->id]);
        $this->assertDatabaseHas('guardian_student', ['guardian_id' => $guardian->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
    }

    public function test_student_financial_access_setting_is_reachable_persistent_and_enforced(): void
    {
        $student = $this->student();
        $this->get(route('finance.index'))->assertSee(route('admin.accounts.index').'#student-finance-access', false);
        $this->get(route('admin.accounts.index'))->assertSee('id="student-finance-access"', false)->assertSee('name="student_finance_visible"', false);
        $this->actingAs($student->user)->get(route('student.finance'))->assertForbidden();
        $before = app(SchoolAccountService::class)->settings();
        $this->actingAs($this->admin)->patch(route('admin.accounts.student-finance'), ['student_finance_visible' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(app(SchoolAccountService::class)->settings()['student_finance_visible']);
        $this->assertSame(array_diff_key($before, ['student_finance_visible' => true]), array_diff_key(app(SchoolAccountService::class)->settings(), ['student_finance_visible' => true]));
        $this->get(route('admin.accounts.index'))->assertSee('value="1" selected', false);
        $this->actingAs($student->user)->get(route('student.finance'))->assertOk();
        $this->patch(route('admin.accounts.student-finance'), ['student_finance_visible' => 0])->assertForbidden();
        $this->actingAs($this->admin)->patch(route('admin.accounts.student-finance'), ['student_finance_visible' => 0])->assertSessionHasNoErrors();
        $this->actingAs($student->user)->get(route('student.finance'))->assertForbidden();
    }

    public function test_supervisor_financial_permissions_can_be_saved_revoked_and_cannot_apply_to_teachers(): void
    {
        $user = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $this->patch(route('admin.users.update', $user), ['role' => 'supervisor', 'status' => 'active', 'financial_permissions_present' => 1, 'financial_permissions' => ['finance.view']])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasPermission('finance.view'));
        $this->assertFalse($user->fresh()->hasPermission('finance.pay'));
        $this->patch(route('admin.users.update', $user), ['role' => 'supervisor', 'status' => 'active', 'financial_permissions_present' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasPermission('finance.view'));
        $this->patch(route('admin.users.update', $user), ['role' => 'teacher', 'status' => 'active', 'financial_permissions_present' => 1, 'financial_permissions' => ['finance.view']])->assertSessionHasErrors('financial_permissions');
    }

    public function test_numbering_settings_save_preserves_student_finance_setting_and_stale_preview_is_rejected(): void
    {
        $this->patch(route('admin.accounts.student-finance'), ['student_finance_visible' => 1])->assertSessionHasNoErrors();
        $settings = app(SchoolAccountService::class)->settings();
        unset($settings['student_finance_visible']);
        $this->post(route('admin.accounts.preview'), $settings)->assertOk();
        $this->put(route('admin.accounts.update'), ['revision' => session('account_numbering_preview.revision')])->assertSessionHasNoErrors();
        $this->assertTrue(app(SchoolAccountService::class)->settings()['student_finance_visible']);
        $this->post(route('admin.accounts.preview'), $settings)->assertOk();
        $revision = session('account_numbering_preview.revision');
        $this->patch(route('admin.accounts.student-finance'), ['student_finance_visible' => 0])->assertSessionHasNoErrors();
        $this->put(route('admin.accounts.update'), ['revision' => $revision])->assertSessionHasErrors('preview');
        $this->assertFalse(app(SchoolAccountService::class)->settings()['student_finance_visible']);
    }

    public function test_financial_permission_update_preserves_supervisor_classroom_assignments(): void
    {
        $student = $this->student();
        $user = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $user->supervisedClassrooms()->attach($student->classroom_id);
        $this->patch(route('admin.users.update', $user), ['role' => 'supervisor', 'status' => 'active', 'financial_permissions_present' => 1, 'financial_permissions' => ['finance.view']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('supervisor_class_assignments', ['supervisor_id' => $user->id, 'classroom_id' => $student->classroom_id]);
        $this->patch(route('admin.users.update', $user), ['role' => 'supervisor', 'status' => 'active', 'classroom_assignments_present' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('supervisor_class_assignments', ['supervisor_id' => $user->id]);
        $this->assertTrue($user->fresh()->hasPermission('finance.view'));
    }

    public function test_only_unassigned_custom_role_can_be_deleted_with_audit(): void
    {
        $this->post(route('admin.roles.store'), ['name' => 'QA removable role', 'permissions' => ['students.view']])->assertSessionHasNoErrors();
        $role = StaffRole::where('name', 'QA removable role')->firstOrFail();
        $this->get(route('admin.roles.index'))->assertOk()->assertSee('action="'.url('/admin/roles/'.$role->id).'"', false)->assertSeeText('حذف الدور');
        $this->delete('/admin/roles/'.$role->id)->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('staff_roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('staff_role_permissions', ['staff_role_id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'staff_roles', 'action' => 'deleted', 'record_id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'status' => 'active']);
    }

    public function test_system_and_unclassified_roles_cannot_be_deleted_even_if_renamed(): void
    {
        $system = StaffRole::where('name', array_key_first(config('permissions.staff_role_labels')))->firstOrFail();
        $this->delete('/admin/roles/'.$system->id)->assertSessionHasErrors('role');
        $this->put(route('admin.roles.update', $system), ['name' => 'QA renamed system', 'permissions' => ['students.view']])->assertSessionHasErrors('name');
        $this->assertDatabaseHas('staff_roles', ['id' => $system->id, 'name' => $system->name]);
        // Historical names alone cannot prove a seeded role is a removable custom role.
        DB::table('staff_roles')->where('id', $system->id)->update(['name' => 'QA historically renamed system']);
        $this->delete('/admin/roles/'.$system->id)->assertSessionHasErrors('role');
        $unknown = StaffRole::create(['name' => 'QA unknown provenance']);
        $this->delete('/admin/roles/'.$unknown->id)->assertSessionHasErrors('role');
        $this->assertDatabaseHas('staff_roles', ['id' => $system->id]);
        $this->assertDatabaseHas('staff_roles', ['id' => $unknown->id]);
    }

    public function test_assigned_custom_role_cannot_be_deleted_even_when_assignment_is_inactive(): void
    {
        $this->post(route('admin.roles.store'), ['name' => 'QA assigned role', 'permissions' => ['students.view']])->assertSessionHasNoErrors();
        $role = StaffRole::where('name', 'QA assigned role')->firstOrFail();
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $staff->staffRoles()->attach($role, ['active' => false]);
        $this->delete('/admin/roles/'.$role->id)->assertSessionHasErrors('role');
        $this->assertDatabaseHas('staff_role_assignments', ['staff_role_id' => $role->id, 'user_id' => $staff->id, 'active' => false]);
        $this->assertDatabaseHas('staff_role_permissions', ['staff_role_id' => $role->id, 'permission' => 'students.view']);
        $this->actingAs($staff)->delete('/admin/roles/'.$role->id)->assertForbidden();
    }

    public function test_custom_role_can_be_renamed_but_failed_audit_rolls_back_its_deletion(): void
    {
        $this->post(route('admin.roles.store'), ['name' => 'QA renameable role', 'permissions' => ['students.view']])->assertSessionHasNoErrors();
        $role = StaffRole::where('name', 'QA renameable role')->firstOrFail();
        $this->put(route('admin.roles.update', $role), ['name' => 'QA renamed custom', 'permissions' => ['students.view']])->assertSessionHasNoErrors();
        $this->assertFalse($role->fresh()->isProtectedFromDeletion());
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: App\\Models\\AuditLog', function ($audit): void {
            if ($audit->action === 'deleted' && $audit->module === 'staff_roles') {
                throw new \RuntimeException('QA audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->delete('/admin/roles/'.$role->id);
            $this->fail('The audit failure must abort deletion.');
        } catch (\RuntimeException $error) {
            $this->assertSame('QA audit failure', $error->getMessage());
        }
        $this->assertDatabaseHas('staff_roles', ['id' => $role->id, 'name' => 'QA renamed custom']);
        $this->assertDatabaseHas('staff_role_permissions', ['staff_role_id' => $role->id, 'permission' => 'students.view']);
        $this->assertDatabaseMissing('audit_logs', ['module' => 'staff_roles', 'action' => 'deleted', 'record_id' => $role->id]);
    }
}
