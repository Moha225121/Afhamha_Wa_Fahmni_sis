<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\LibraryResource;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class QaRoundTwoAdministrationTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->actingAs($this->admin);
    }

    public function test_teacher_and_parent_search_support_partial_arabic_case_insensitive_latin_phone_and_combined_status(): void
    {
        foreach (['teachers' => Teacher::class, 'parents' => Guardian::class] as $route => $model) {
            $role = $route === 'teachers' ? 'teacher' : 'parent';
            $one = $model::create(['user_id' => User::factory()->create(['role' => $role, 'name' => 'أمل اختبار', 'email' => $role.'Alpha@example.test', 'phone' => '0910000011'])->id, 'status' => 'active']);
            $two = $model::create(['user_id' => User::factory()->create(['role' => $role, 'name' => 'أمل أخرى', 'email' => $role.'Beta@example.test'])->id, 'status' => 'inactive']);
            foreach (['أمل', 'ALPHA', '0000011', '  أمل  '] as $term) {
                $this->get(route('admin.'.$route.'.index', ['q' => $term, 'status' => 'active']))->assertOk()
                    ->assertViewHas($route, fn ($rows) => $rows->modelKeys() === [$one->id]);
            }
            $this->get(route('admin.'.$route.'.index', ['q' => 'nothing'] ))->assertOk()->assertViewHas($route, fn ($rows) => $rows->isEmpty());
            $this->get(route('admin.'.$route.'.index', ['status' => 'inactive']))->assertOk()->assertViewHas($route, fn ($rows) => $rows->modelKeys() === [$two->id]);
            $this->get(route('admin.'.$route.'.index'))->assertOk()->assertViewHas($route, fn ($rows) => $rows->total() === 2)->assertSeeText('إعادة الضبط');
        }
    }

    public function test_schedules_combine_section_and_sunday_zero_without_losing_break_rows(): void
    {
        $fixture = $this->createStudentFixture('TIMETABLE');
        $class = $fixture['classroom'];
        $class->update(['name' => 'الصف الأول', 'section' => 'أ']);
        $second = Classroom::create(['name' => $class->name, 'section' => 'ب', 'stage' => $class->stage, 'academic_year_id' => $class->academic_year_id]);
        $subject = $this->createSubjectFor($class, 'TIME');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        foreach ([[$class->id, 0], [$class->id, 1], [$second->id, 0]] as [$classroomId, $day]) {
            DB::table('schedules')->insert(['classroom_id' => $classroomId, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'day_of_week' => $day, 'period_number' => 3, 'starts_at' => '10:00', 'ends_at' => '10:45', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get(route('admin.schedules.index', ['section' => 'أ', 'day_of_week' => '0']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (int) $rows->first()->day_of_week === 0)
            ->assertSeeText('استراحة مدرسية')->assertSee('name="day_of_week"', false)->assertSee('name="section"', false);
        $this->get(route('admin.schedules.index', ['classroom_id' => $second->id, 'section' => 'أ']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->get(route('admin.schedules.index'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
    }

    public function test_library_grade_choices_come_from_configured_classes_and_visible_books(): void
    {
        $fixture = $this->createStudentFixture('LIB');
        $fixture['classroom']->update(['name' => 'الصف الثاني', 'section' => 'أ']);
        $subject = $this->createSubjectFor($fixture['classroom'], 'LIB');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $teacher->classrooms()->attach($fixture['classroom']->id, ['subject_id' => $subject->id]);
        $this->get(route('admin.library.index'))->assertOk()->assertViewHas('grades', fn ($grades) => array_keys($grades) === [2]);
        $this->actingAs($teacher->user)->get(route('teacher.library.index'))->assertOk()->assertSeeText('اطلب من إدارة المدرسة');
        $book = LibraryResource::create(['title' => 'كتاب مصرح', 'subject_id' => $subject->id, 'classroom_id' => $fixture['classroom']->id, 'grade_levels' => [2], 'file_path' => 'fixture.pdf', 'disk' => 'local', 'audience' => 'teachers', 'status' => 'active', 'created_by' => $this->admin->id, 'is_public' => false]);
        $this->get(route('teacher.library.index', ['grade' => 2]))->assertOk()->assertSeeText($book->title);
        $book->update(['grade_levels' => null]);
        $this->get(route('teacher.library.index', ['grade' => 2]))->assertOk()->assertSeeText($book->title);
        $this->get(route('teacher.library.index', ['grade' => 3]))->assertOk()->assertDontSeeText($book->title);
        $book->update(['status' => 'inactive']);
        $this->get(route('teacher.library.index'))->assertOk()->assertDontSeeText($book->title);
        $this->get(route('teacher.library.file', $book))->assertNotFound();
    }

    public function test_subject_deletion_preserves_links_and_lessons_and_allows_unused_subject(): void
    {
        $fixture = $this->createStudentFixture('SUBJECT');
        $subject = $this->createSubjectFor($fixture['classroom'], 'REFERENCED');
        $this->delete(route('admin.subjects.destroy', $subject))->assertSessionHasErrors('subject');
        $this->assertDatabaseHas('classroom_subject', ['subject_id' => $subject->id, 'classroom_id' => $fixture['classroom']->id]);
        $this->patch(route('admin.module.toggle', ['module' => 'subjects', 'id' => $subject->id]), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $subject->fresh()->status);
        $unused = Subject::create(['name' => 'غير مستخدمة', 'code' => 'UNUSED', 'status' => 'inactive', 'stage' => 'أساسي']);
        $this->delete(route('admin.subjects.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('subjects', ['id' => $unused->id]);
        $this->delete(route('admin.subjects.destroy', $unused))->assertNotFound();
    }

    public function test_supervisor_and_user_delete_allow_only_unreferenced_records(): void
    {
        $fixture = $this->createStudentFixture('SUPERVISOR');
        $supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'inactive']);
        $supervisor->supervisedClassrooms()->attach($fixture['classroom']);
        $this->delete(route('admin.supervisors.destroy', $supervisor))->assertSessionHasErrors('user');
        $this->assertDatabaseHas('supervisor_class_assignments', ['supervisor_id' => $supervisor->id]);
        $this->delete(route('admin.users.destroy', $fixture['user']))->assertSessionHasErrors('user');
        $this->assertDatabaseHas('students', ['id' => $fixture['student']->id]);
        $unused = User::factory()->create(['role' => 'supervisor', 'status' => 'inactive']);
        $this->delete(route('admin.supervisors.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $unused->id]);
        $unusedUser = User::factory()->create(['role' => 'staff']);
        $this->delete(route('admin.users.destroy', $unusedUser))->assertSessionHasNoErrors();
        $this->delete(route('admin.users.destroy', $this->admin))->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'status' => 'active']);
    }

    public function test_user_status_and_profile_remain_consistent_and_profile_roles_cannot_be_reassigned(): void
    {
        $fixture = $this->createStudentFixture('STATUS');
        $this->patch(route('admin.users.update', $fixture['user']), ['role' => 'student', 'status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $fixture['student']->fresh()->status);
        $this->patch(route('admin.users.update', $fixture['user']), ['role' => 'teacher', 'status' => 'active'])->assertSessionHasErrors('role');
        $this->assertSame('student', $fixture['user']->fresh()->role);
        $this->patch(route('admin.module.toggle', ['module' => 'students', 'id' => $fixture['student']->id]), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->patch(route('admin.module.toggle', ['module' => 'students', 'id' => $fixture['student']->id]), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $fixture['user']->fresh()->status);
        $fixture['student']->delete();
        $this->patch(route('admin.users.update', $fixture['user']), ['role' => 'student', 'status' => 'active'])->assertSessionHasErrors('user');
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        foreach (['teacher', 'student', 'parent'] as $role) {
            $this->patch(route('admin.users.update', $staff), ['role' => $role, 'status' => 'active'])->assertSessionHasErrors('role');
            $this->assertSame('staff', $staff->fresh()->role);
        }
    }

    public function test_parent_with_zero_visible_children_but_conversation_history_remains_protected_and_can_be_stopped(): void
    {
        $parentUser = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $parent = Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);
        $conversation = Conversation::create(['created_by' => $parentUser->id, 'subject' => 'سجل محفوظ']);
        $conversation->participants()->attach([$this->admin->id, $parentUser->id]);
        $message = $conversation->messages()->create(['sender_id' => $parentUser->id, 'body' => 'رسالة اختبار محفوظة']);
        $this->assertSame(0, $parent->students()->count());
        $this->delete(route('admin.parents.destroy', $parent))->assertSessionHasErrors('parent');
        $this->patch(route('admin.module.toggle', ['module' => 'parents', 'id' => $parent->id]), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $parent->fresh()->status);
        $this->assertSame('inactive', $parentUser->fresh()->status);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'sender_id' => $parentUser->id]);
    }

    public function test_supervisor_assignment_dialog_is_not_limited_by_user_page_or_role_filters_and_changes_persist(): void
    {
        $fixture = $this->createStudentFixture('ASSIGN');
        User::factory()->count(24)->create(['role' => 'staff']);
        $supervisors = User::factory()->count(5)->create(['role' => 'supervisor', 'status' => 'active']);
        $this->get(route('admin.users.index', ['role' => 'staff', 'page' => 2]))->assertOk()
            ->assertViewHas('supervisors', fn ($rows) => $rows->count() === 5)->assertSee('supervisor-assignment-grid', false);
        $one = $supervisors->first();
        $this->patch(route('admin.users.update', $one), ['role' => 'supervisor', 'status' => 'active', 'classroom_assignments_present' => 1, 'classroom_ids' => [$fixture['classroom']->id]])->assertSessionHasNoErrors();
        $this->assertSame([$fixture['classroom']->id], $one->fresh()->supervisedClassrooms->modelKeys());
        $this->get(route('admin.users.index', ['role' => 'staff']))->assertSee('value="'.$fixture['classroom']->id.'" checked', false);
    }

    public function test_conversation_archive_reopen_and_empty_delete_preserve_authorship_and_participant_scope(): void
    {
        $parent = User::factory()->create(['role' => 'parent']);
        Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $conversation = Conversation::create(['created_by' => $parent->id, 'status' => 'open']);
        $conversation->participants()->attach([$parent->id, $this->admin->id]);
        $message = $conversation->messages()->create(['sender_id' => $parent->id, 'body' => 'رسالة محفوظة']);
        $this->patch(route('admin.conversations.status', $conversation), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->patch(route('admin.conversations.status', $conversation), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->get(route('admin.conversations.index', ['status' => 'closed']))->assertViewHas('conversations', fn ($rows) => $rows->modelKeys() === [$conversation->id]);
        $this->post(route('admin.conversations.messages.store', $conversation), ['body' => 'cannot send'])->assertForbidden();
        $this->delete(route('admin.conversations.destroy', $conversation))->assertSessionHasErrors('conversation');
        $this->patch(route('admin.conversations.status', $conversation), ['status' => 'open'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'sender_id' => $parent->id, 'body' => 'رسالة محفوظة']);
        $empty = Conversation::create(['created_by' => $this->admin->id]);
        $empty->participants()->attach([$this->admin->id, $parent->id]);
        $this->delete(route('admin.conversations.destroy', $empty))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('conversations', ['id' => $empty->id]);
        $outsider = $this->createAdmin();
        $this->actingAs($outsider)->patch(route('admin.conversations.status', $conversation), ['status' => 'closed'])->assertNotFound();
        $this->actingAs($parent)->patch(route('admin.conversations.status', $conversation), ['status' => 'closed'])->assertForbidden();
    }

    public function test_fee_definition_lifecycle_preserves_unpaid_partial_and_paid_accounts_and_filtered_totals(): void
    {
        $fixture = $this->createStudentFixture('FEES');
        $student = $fixture['student'];
        $finance = app(StudentFinanceService::class);
        foreach ([0, 25, 100] as $paid) {
            $template = $finance->createTemplate(['name' => 'QA fee '.$paid, 'amount' => '100', 'fee_type_id' => DB::table('fee_types')->value('id'), 'academic_year_id' => $fixture['classroom']->academic_year_id, 'due_date' => now()->addMonth()->toDateString(), 'required' => true, 'active' => true]);
            $this->post(route('finance.templates.apply', $template))->assertSessionHasNoErrors();
            $this->post(route('finance.templates.apply', $template))->assertSessionHasNoErrors();
            $this->assertSame(1, DB::table('student_fees')->where('fee_template_id', $template)->count());
            $fee = DB::table('student_fees')->where('fee_template_id', $template)->first();
            if ($paid) $finance->pay($student, ['student_installment_id' => DB::table('student_installments')->where('student_fee_id', $fee->id)->value('id'), 'amount' => (string) $paid, 'paid_on' => now()->toDateString(), 'payment_method_id' => DB::table('payment_methods')->value('id'), 'request_key' => (string) Str::uuid()]);
            $before = $finance->statement($student)['totals'];
            $this->patch(route('finance.templates.toggle', $template), ['active' => 0])->assertSessionHasNoErrors();
            $this->delete(route('finance.templates.destroy', $template))->assertSessionHasErrors('fee');
            $this->assertSame($before, $finance->statement($student)['totals']);
        }
        $this->get(route('finance.index', ['classroom_id' => $fixture['classroom']->id]))->assertOk()->assertViewHas('dashboard', fn ($totals) => $totals['total'] === 300000 && $totals['paid'] === 125000 && $totals['remaining'] === 175000);
        $unused = $finance->createTemplate(['name' => 'unused', 'amount' => '10', 'fee_type_id' => DB::table('fee_types')->value('id'), 'academic_year_id' => $fixture['classroom']->academic_year_id, 'due_date' => now()->addMonth()->toDateString(), 'required' => true, 'active' => false]);
        $this->delete(route('finance.templates.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('fee_templates', ['id' => $unused]);
    }

    public function test_replacing_student_photo_does_not_remove_another_users_shared_file(): void
    {
        Storage::fake('public');
        $fixture = $this->createStudentFixture('PHOTO');
        $path = UploadedFile::fake()->image('shared.jpg')->store('student-photos', 'public');
        $fixture['user']->update(['avatar_path' => $path]);
        $other = User::factory()->create(['avatar_path' => $path]);
        $this->put(route('admin.students.update', $fixture['student']), ['name' => $fixture['user']->name, 'email' => $fixture['user']->email, 'student_number' => $fixture['student']->student_number, 'status' => 'active', 'photo' => UploadedFile::fake()->image('new.jpg')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $other->fresh()->avatar_path);
        $this->assertNotSame($path, $fixture['user']->fresh()->avatar_path);
        $this->get(route('admin.students.edit', $fixture['student']))->assertOk()->assertSee(Storage::disk('public')->url($fixture['user']->fresh()->avatar_path));
    }

    public function test_non_admin_cannot_use_admin_lifecycle_endpoints(): void
    {
        $fixture = $this->createStudentFixture('AUTH');
        $subject = $this->createSubjectFor($fixture['classroom'], 'AUTH');
        $this->actingAs($fixture['user']);
        $this->delete(route('admin.subjects.destroy', $subject))->assertForbidden();
        $this->delete(route('admin.users.destroy', $this->admin))->assertForbidden();
        $this->patch(route('admin.module.toggle', ['module' => 'subjects', 'id' => $subject->id]), ['status' => 'inactive'])->assertForbidden();
        $this->delete(route('finance.templates.destroy', 1))->assertForbidden();
        $this->delete(route('admin.supervisors.destroy', $this->admin))->assertForbidden();
    }

    public function test_teacher_with_assignments_can_be_deactivated_without_losing_assignments_and_unused_parent_can_be_removed(): void
    {
        $fixture = $this->createStudentFixture('TEACHER');
        $subject = $this->createSubjectFor($fixture['classroom'], 'TEACHER');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $teacher->classrooms()->attach($fixture['classroom']->id, ['subject_id' => $subject->id]);
        $this->delete(route('admin.teachers.destroy', $teacher))->assertSessionHasErrors('teacher');
        $this->patch(route('admin.module.toggle', ['module' => 'teachers', 'id' => $teacher->id]), ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teacher->id, 'subject_id' => $subject->id]);
        $this->assertSame('inactive', $teacher->fresh()->status);
        $this->assertSame('inactive', $teacher->fresh()->user->status);
        $parentUser = User::factory()->create(['role' => 'parent']);
        $parent = Guardian::create(['user_id' => $parentUser->id, 'status' => 'active']);
        $this->delete(route('admin.parents.destroy', $parent))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('guardians', ['id' => $parent->id]);
        $this->assertSame('inactive', $parentUser->fresh()->status);
    }

    public function test_last_admin_and_notification_history_are_protected_even_in_service_calls(): void
    {
        $otherActor = User::factory()->create(['role' => 'staff']);
        $this->actingAs($otherActor);
        foreach (['delete', 'deactivate'] as $action) {
            try {
                $service = app(\App\Services\AdminRecordLifecycle::class);
                $action === 'delete' ? $service->deleteUser($this->admin) : $service->setUserStatus($this->admin, 'inactive');
                $this->fail('Last active administrator must remain protected.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('user', $exception->errors());
            }
        }
        $this->assertSame('active', $this->admin->fresh()->status);
        $this->actingAs($this->admin);
        $otherActor->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'fixture', 'data' => ['title' => 'سجل اختبار']]);
        $this->delete(route('admin.users.destroy', $otherActor))->assertSessionHasErrors('user');
        $this->assertSame(1, $otherActor->notifications()->count());
    }

    public function test_card_print_scope_handles_selected_class_multiple_students_invalid_input_and_empty_results(): void
    {
        $one = $this->createStudentFixture('CARDS-A');
        $two = $this->createStudentFixture('CARDS-B');
        $this->get(route('admin.students.cards', ['classroom_id' => $one['classroom']->id]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->modelKeys() === [$one['student']->id]);
        $this->get(route('admin.students.cards'))->assertOk()->assertViewHas('students', fn ($rows) => $rows->count() === 2);
        $this->get(route('admin.students.cards', ['classroom_id' => 'not-an-id']))->assertSessionHasErrors('classroom_id');
        $this->get(route('admin.students.cards', ['status' => 'inactive']))->assertNotFound();
        $this->actingAs($one['user'])->get(route('admin.students.cards'))->assertForbidden();
    }
}
