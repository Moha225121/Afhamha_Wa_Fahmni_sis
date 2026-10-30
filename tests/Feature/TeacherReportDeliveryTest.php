<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherReportPush;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\NotificationDestination;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TeacherReportDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Classroom $room;

    private Classroom $foreignRoom;

    private Subject $subject;

    private Student $first;

    private Student $second;

    private Student $unlinked;

    private Student $foreign;

    private Guardian $sharedParent;

    private Guardian $coParent;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nasbia.external_teacher_reports' => true]);
        $this->withoutVite();
        $this->travelTo('2026-10-28 10:00:00');
        Http::preventStrayRequests();
        Bus::fake();
        config(['fcm.enabled' => false, 'services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        DB::table('settings')->updateOrInsert(['key' => 'timezone'], ['value' => 'Africa/Tripoli']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $this->room = Classroom::create(['name' => 'الصف الأول', 'section' => 'C', 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
        $this->foreignRoom = Classroom::create(['name' => 'الثالث ثانوي', 'section' => null, 'stage' => 'ثانوي', 'academic_year_id' => $year->id]);
        $this->subject = Subject::create(['name' => 'الرياضيات', 'code' => 'DELIVERY-MATH', 'stage' => 'أساسي', 'status' => 'active']);
        $this->teacherUser = User::factory()->create(['name' => 'المعلمة مريم', 'role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->teacher->classrooms()->attach($this->room, ['subject_id' => $this->subject->id]);
        $this->room->subjects()->attach($this->subject);
        $this->first = $this->student('أحمد طالب التقرير', 'REPORT-SEND-1', $this->room);
        $this->second = $this->student('سارة طالبة التقرير', 'REPORT-SEND-2', $this->room);
        $this->unlinked = $this->student('عمر بلا ولي مرتبط', 'REPORT-SEND-3', $this->room);
        $this->foreign = $this->student('طالب خارج الصف', 'REPORT-SEND-FOREIGN', $this->foreignRoom);
        $this->sharedParent = $this->guardian('ولي أحمد وسارة', [$this->first, $this->second, $this->foreign]);
        $this->coParent = $this->guardian('ولي إضافي لأحمد', [$this->first]);
        $this->admin = User::factory()->create(['name' => 'إدارة المدرسة', 'role' => 'admin', 'status' => 'active']);
        $this->otherAdmin = User::factory()->create(['name' => 'إدارة أخرى', 'role' => 'admin', 'status' => 'active']);
        $exam = Exam::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->room->id, 'subject_id' => $this->subject->id, 'title' => 'اختبار التقرير', 'starts_at' => '2026-10-28 08:00:00', 'duration_minutes' => 30, 'total_score' => 20, 'status' => 'published']);
        foreach ([[$this->first, 16], [$this->second, 18], [$this->unlinked, 12], [$this->foreign, 20]] as [$student, $score]) {
            DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $student->id, 'score' => $score, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function student(string $name, string $number, Classroom $room, string $status = 'active'): Student
    {
        return Student::create(['user_id' => User::factory()->create(['name' => $name, 'role' => 'student', 'status' => 'active'])->id, 'student_number' => $number, 'classroom_id' => $room->id, 'status' => $status]);
    }

    private function guardian(string $name, array $students, string $status = 'active', string $userStatus = 'active', string $role = 'parent'): Guardian
    {
        $guardian = Guardian::create(['user_id' => User::factory()->create(['name' => $name, 'role' => $role, 'status' => $userStatus])->id, 'status' => $status]);
        $guardian->students()->attach(array_map(fn (Student $student) => $student->id, $students));

        return $guardian;
    }

    private function savedReport(array $entryOverrides = []): array
    {
        $entries = [];
        if ($entryOverrides !== []) {
            $entries[] = array_replace(['student_id' => $this->first->id, 'subject_id' => $this->subject->id, 'score_mode' => 'auto', 'rating_mode' => 'auto', 'behaviour' => null, 'activity' => '', 'notes' => ''], $entryOverrides);
        }

        return $this->actingAs($this->teacherUser)->postJson('/teacher/reports', [
            'classroom_id' => $this->room->id, 'title' => 'تقرير متابعة الطلاب',
            'config' => ['report_type' => 'overall', 'subject_ids' => [$this->subject->id]], 'entries' => $entries,
        ])->assertCreated()->json('builder');
    }

    private function deliveryPayload(array $report, array $audiences = ['student', 'parent'], ?array $studentIds = null): array
    {
        return ['revision' => $report['report']['revision'], 'audiences' => $audiences, 'student_ids' => $studentIds ?? [$this->first->id, $this->second->id, $this->unlinked->id]];
    }

    private function send(array $report, array $audiences = ['student', 'parent'], ?array $studentIds = null, ?string $requestId = null): array
    {
        return $this->actingAs($this->teacherUser)->postJson('/teacher/reports/'.$report['report']['id'].'/send',
            $this->deliveryPayload($report, $audiences, $studentIds) + ['request_id' => $requestId ?? (string) Str::uuid()])
            ->assertOk()->json();
    }

    private function deliveries(?User $user = null)
    {
        return DB::table('teacher_report_deliveries')->when($user, fn ($query) => $query->where('recipient_user_id', $user->id))->orderBy('id')->get();
    }

    public function test_nisbiya_default_policy_blocks_external_reports_without_creating_dispatches(): void
    {
        config(['nasbia.external_teacher_reports' => false]);
        $report = $this->savedReport();
        $this->actingAs($this->teacherUser)->get('/teacher/reports/'.$report['report']['id'])->assertOk()
            ->assertDontSee('value="student" data-trp-audience', false)
            ->assertDontSee('value="parent" data-trp-audience', false)
            ->assertSee('value="admin" data-trp-audience', false);
        $payload = $this->deliveryPayload($report) + ['request_id' => (string) Str::uuid()];
        $this->actingAs($this->teacherUser)->postJson('/teacher/reports/'.$report['report']['id'].'/send-preview', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('audiences');
        $this->postJson('/teacher/reports/'.$report['report']['id'].'/send', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('audiences');
        $this->assertDatabaseCount('teacher_report_dispatches', 0);
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
    }

    private function snapshot(object $delivery): array
    {
        return json_decode($delivery->snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    private function deliveryUrl(object $delivery): string
    {
        return '/received-reports/'.$delivery->id;
    }

    public function test_the_recipient_picker_lists_only_saved_current_active_students_and_real_active_parents_and_admins(): void
    {
        $report = $this->savedReport();
        $this->second->update(['classroom_id' => $this->foreignRoom->id]);
        $this->unlinked->user->update(['status' => 'inactive']);
        $this->student('طالب جديد ليس في التقرير المحفوظ', 'REPORT-SEND-NEW', $this->room);
        $this->guardian('ولي غير نشط', [$this->first], 'inactive');
        $this->guardian('حساب ولي غير نشط', [$this->first], 'active', 'inactive');
        $this->guardian('حساب ليس ولي أمر', [$this->first], 'active', 'active', 'teacher');
        User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        $this->guardian('ولي محذوف', [$this->first])->delete();
        $response = $this->actingAs($this->teacherUser)->getJson('/teacher/reports/'.$report['report']['id'].'/recipients')->assertOk();
        $this->assertSame([$this->first->id], array_column($response->json('students'), 'id'));
        $this->assertSame(2, $response->json('students.0.parents_count'));
        $this->assertSame(2, $response->json('admins_count'));
        $response->assertDontSee('REPORT-SEND-', false);
    }

    public function test_preview_counts_each_private_student_parent_copy_and_selected_admin_roster_without_writing_anything(): void
    {
        $report = $this->savedReport();
        $tables = ['teacher_report_dispatches', 'teacher_report_deliveries', 'teacher_reports', 'notifications', 'grades', 'students'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $preview = $this->actingAs($this->teacherUser)->postJson('/teacher/reports/'.$report['report']['id'].'/send-preview',
            $this->deliveryPayload($report, ['student', 'parent', 'admin']))->assertOk()->json();
        $this->assertSame(['student' => 3, 'parent' => 3, 'admin' => 2, 'total' => 8], $preview['counts']);
        $this->assertSame(3, $preview['students_count']);
        $this->assertSame([$this->unlinked->id], array_column($preview['unlinked_students'], 'id'));
        $this->assertSame($before, $snapshot());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_student_delivery_contains_only_that_students_immutable_report_and_never_a_classmate_or_parent_copy(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student'], [$this->first->id, $this->second->id]);
        $this->assertDatabaseCount('teacher_report_dispatches', 1);
        $this->assertDatabaseCount('teacher_report_deliveries', 2);
        $this->assertCount(0, $this->deliveries($this->sharedParent->user));
        Bus::assertDispatched(SendTeacherReportPush::class, 2);
        foreach ([[$this->first, $this->second], [$this->second, $this->first]] as [$student, $classmate]) {
            $delivery = $this->deliveries($student->user)->sole();
            Bus::assertDispatched(SendTeacherReportPush::class, fn (SendTeacherReportPush $job) => $job->deliveryId === $delivery->id
                && $job->connection === 'database' && $job->queue === 'notifications' && $job->afterCommit === true);
            $this->assertSame('student', $delivery->audience);
            $this->assertEquals($student->id, $delivery->student_id);
            $snapshot = $this->snapshot($delivery);
            $this->assertSame([$student->id], array_unique(array_column($snapshot['entries'], 'student_id')));
            $this->assertArrayNotHasKey('catalog', $snapshot);
            $this->assertStringNotContainsString($classmate->user->name, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('REPORT-SEND-', json_encode($snapshot));
            $this->actingAs($student->user)->get($this->deliveryUrl($delivery))->assertOk()
                ->assertSeeText($student->user->name)->assertDontSeeText($classmate->user->name);
        }
    }

    public function test_a_shared_parent_receives_separate_child_specific_reports_and_only_selected_child_guardians_receive_them(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['parent'], [$this->first->id, $this->second->id]);
        $this->assertDatabaseCount('teacher_report_deliveries', 3);
        $this->assertCount(2, $this->deliveries($this->sharedParent->user));
        $this->assertCount(1, $this->deliveries($this->coParent->user));
        $this->assertCount(0, $this->deliveries($this->first->user));
        foreach ($this->deliveries($this->sharedParent->user) as $delivery) {
            $this->assertSame('parent', $delivery->audience);
            $this->assertSame([(int) $delivery->student_id], array_unique(array_column($this->snapshot($delivery)['entries'], 'student_id')));
            $other = (int) $delivery->student_id === $this->first->id ? $this->second : $this->first;
            $this->actingAs($this->sharedParent->user)->get($this->deliveryUrl($delivery))->assertOk()->assertDontSeeText($other->user->name);
        }
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $this->deliveries($this->sharedParent->user)->pluck('student_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_unlinked_students_still_receive_student_copies_and_parent_only_empty_audience_is_a_clear_validation_error(): void
    {
        $report = $this->savedReport();
        $path = '/teacher/reports/'.$report['report']['id'];
        $payload = $this->deliveryPayload($report, ['parent'], [$this->unlinked->id]);
        $this->actingAs($this->teacherUser)->postJson($path.'/send-preview', $payload)->assertOk()
            ->assertJsonPath('counts.total', 0)->assertJsonPath('unlinked_students.0.id', $this->unlinked->id);
        $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
        $this->send($report, ['student', 'parent'], [$this->unlinked->id]);
        $this->assertDatabaseCount('teacher_report_deliveries', 1);
        $this->assertCount(1, $this->deliveries($this->unlinked->user));
    }

    public function test_admin_delivery_contains_only_selected_students_and_reaches_only_active_admin_accounts(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        $report = $this->savedReport();
        $this->send($report, ['admin'], [$this->first->id, $this->unlinked->id]);
        $this->assertDatabaseCount('teacher_report_deliveries', 2);
        foreach ([$this->admin, $this->otherAdmin] as $admin) {
            $delivery = $this->deliveries($admin)->sole();
            $this->assertSame('admin', $delivery->audience);
            $this->assertNull($delivery->student_id);
            $this->assertEqualsCanonicalizing([$this->first->id, $this->unlinked->id], array_unique(array_column($this->snapshot($delivery)['entries'], 'student_id')));
            $this->actingAs($admin)->get($this->deliveryUrl($delivery))->assertOk()->assertSeeText($this->first->user->name)
                ->assertSeeText($this->unlinked->user->name)->assertDontSeeText($this->second->user->name);
        }
        $this->assertCount(0, $this->deliveries($inactiveAdmin));
        $this->assertCount(0, $this->deliveries($this->first->user));
        $this->assertCount(0, $this->deliveries($this->sharedParent->user));
    }

    public function test_mixed_audiences_do_not_expand_the_selected_roster_or_notify_inactive_unrelated_accounts(): void
    {
        $excluded = [
            $this->guardian('ولي غير نشط', [$this->first], 'inactive')->user,
            $this->guardian('حساب غير نشط', [$this->first], 'active', 'inactive')->user,
            $this->guardian('ليس ولي أمر', [$this->first], 'active', 'active', 'teacher')->user,
            $this->guardian('ولي طالب آخر', [$this->foreign])->user,
            $this->second->user,
        ];
        $report = $this->savedReport();
        $response = $this->send($report, ['student', 'parent', 'admin'], [$this->first->id]);
        $this->assertSame(['student' => 1, 'parent' => 2, 'admin' => 2, 'total' => 5], $response['counts']);
        $this->assertDatabaseCount('teacher_report_deliveries', 5);
        foreach ($excluded as $user) {
            $this->assertCount(0, $this->deliveries($user));
            $this->assertSame(0, $user->notifications()->count());
        }
    }

    public function test_foreign_inactive_moved_and_unsaved_students_are_rejected_without_partial_delivery(): void
    {
        $report = $this->savedReport();
        $newStudent = $this->student('طالب جديد لم يُضم للتقرير', 'REPORT-SEND-NEW', $this->room);
        $this->second->update(['classroom_id' => $this->foreignRoom->id]);
        $this->unlinked->update(['status' => 'inactive']);
        foreach ([$this->foreign->id, $newStudent->id, $this->second->id, $this->unlinked->id] as $id) {
            $payload = $this->deliveryPayload($report, ['student', 'parent', 'admin'], [$this->first->id, $id]);
            $this->actingAs($this->teacherUser)->postJson('/teacher/reports/'.$report['report']['id'].'/send-preview', $payload)->assertUnprocessable();
            $this->postJson('/teacher/reports/'.$report['report']['id'].'/send', $payload + ['request_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('teacher_report_dispatches', 0);
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_only_the_report_owner_with_a_current_assignment_can_preview_or_send(): void
    {
        $report = $this->savedReport();
        $path = '/teacher/reports/'.$report['report']['id'];
        $payload = $this->deliveryPayload($report, ['student'], [$this->first->id]);
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $other = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $other->classrooms()->attach($this->room, ['subject_id' => $this->subject->id]);
        $this->actingAs($otherUser)->getJson($path.'/recipients')->assertNotFound();
        $this->postJson($path.'/send-preview', $payload)->assertNotFound();
        $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertNotFound();
        foreach ([$this->admin, $this->first->user, $this->sharedParent->user] as $user) {
            $this->actingAs($user)->getJson($path.'/recipients')->assertForbidden();
            $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertForbidden();
        }
        DB::table('teacher_assignments')->where('teacher_id', $this->teacher->id)->delete();
        $this->actingAs($this->teacherUser)->getJson($path.'/recipients')->assertNotFound();
        $this->postJson($path.'/send-preview', $payload)->assertNotFound();
        $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
    }

    public function test_a_changed_saved_revision_cannot_be_sent_from_a_stale_preview(): void
    {
        $report = $this->savedReport();
        $path = '/teacher/reports/'.$report['report']['id'];
        $payload = $this->deliveryPayload($report, ['student'], [$this->first->id]);
        $this->actingAs($this->teacherUser)->postJson($path.'/send-preview', $payload)->assertOk();
        $this->putJson($path, ['classroom_id' => $this->room->id, 'title' => 'عنوان أحدث', 'config' => $report['config'], 'revision' => $report['report']['revision'], 'entries' => []])->assertOk();
        $this->postJson($path.'/send-preview', $payload)->assertConflict();
        $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('teacher_report_dispatches', 0);
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_request_uuid_is_idempotent_and_cannot_be_reused_for_a_different_recipient_set(): void
    {
        $report = $this->savedReport();
        $request = (string) Str::uuid();
        $first = $this->send($report, ['student', 'parent'], [$this->first->id, $this->second->id], $request);
        $notifications = DB::table('notifications')->orderBy('id')->get()->toJson();
        $deliveries = $this->deliveries()->toJson();
        $retry = $this->send($report, ['parent', 'student'], [$this->second->id, $this->first->id], $request);
        $this->assertSame($first, $retry);
        $this->assertSame($request, $retry['dispatch_id']);
        $this->assertDatabaseCount('teacher_report_dispatches', 1);
        $this->assertSame($notifications, DB::table('notifications')->orderBy('id')->get()->toJson());
        $this->assertSame($deliveries, $this->deliveries()->toJson());
        $this->postJson('/teacher/reports/'.$report['report']['id'].'/send', $this->deliveryPayload($report, ['admin'], [$this->first->id]) + ['request_id' => $request])->assertConflict();
        $this->assertSame($notifications, DB::table('notifications')->orderBy('id')->get()->toJson());
        $this->assertSame($deliveries, $this->deliveries()->toJson());
    }

    public function test_invalid_requests_never_implicitly_send_to_the_whole_class(): void
    {
        $report = $this->savedReport();
        $path = '/teacher/reports/'.$report['report']['id'].'/send';
        $base = $this->deliveryPayload($report, ['student'], [$this->first->id]) + ['request_id' => (string) Str::uuid()];
        foreach ([['student_ids' => []], ['student_ids' => null], ['student_ids' => [$this->first->id, $this->first->id]], ['audiences' => []], ['audiences' => ['unknown']], ['revision' => 0], ['request_id' => 'not-a-uuid']] as $override) {
            $this->actingAs($this->teacherUser)->postJson($path, array_replace($base, $override))->assertUnprocessable();
        }
        $this->assertDatabaseCount('teacher_report_dispatches', 0);
        $this->assertDatabaseCount('teacher_report_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_received_links_are_bound_to_the_recipient_and_recheck_the_current_guardian_child_link(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student', 'parent'], [$this->first->id]);
        $studentCopy = $this->deliveries($this->first->user)->sole();
        $parentCopy = $this->deliveries($this->sharedParent->user)->sole();
        $this->actingAs($this->first->user)->get($this->deliveryUrl($studentCopy))->assertOk();
        $this->assertNotNull(DB::table('teacher_report_deliveries')->where('id', $studentCopy->id)->value('read_at'));
        $this->get($this->deliveryUrl($parentCopy))->assertNotFound();
        $this->actingAs($this->second->user)->get($this->deliveryUrl($studentCopy))->assertNotFound();
        $this->actingAs($this->admin)->get($this->deliveryUrl($studentCopy))->assertNotFound();
        $this->actingAs($this->sharedParent->user)->get($this->deliveryUrl($parentCopy))->assertOk();
        $this->sharedParent->students()->detach($this->first);
        $this->get($this->deliveryUrl($parentCopy))->assertNotFound();
        $this->actingAs($this->coParent->user)->get($this->deliveryUrl($parentCopy))->assertNotFound();
    }

    public function test_received_student_and_parent_copies_require_current_active_accounts_and_child_records(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student', 'parent'], [$this->first->id]);
        $studentCopy = $this->deliveries($this->first->user)->sole();
        $parentCopy = $this->deliveries($this->sharedParent->user)->sole();
        $this->first->update(['status' => 'inactive']);
        $this->actingAs($this->first->user)->get($this->deliveryUrl($studentCopy))->assertNotFound();
        $this->actingAs($this->sharedParent->user)->get($this->deliveryUrl($parentCopy))->assertNotFound();
        $this->first->update(['status' => 'active']);
        $this->sharedParent->update(['status' => 'inactive']);
        $this->get($this->deliveryUrl($parentCopy))->assertNotFound();
    }

    public function test_delivery_snapshot_does_not_change_after_teacher_edits_or_loses_the_class_assignment(): void
    {
        $report = $this->savedReport(['notes' => 'ملاحظة التقرير الأصلي']);
        $this->send($report, ['student'], [$this->first->id]);
        $delivery = $this->deliveries($this->first->user)->sole();
        $original = $delivery->snapshot;
        $this->actingAs($this->teacherUser)->putJson('/teacher/reports/'.$report['report']['id'], [
            'classroom_id' => $this->room->id, 'title' => 'عنوان بعد الإرسال', 'config' => $report['config'], 'revision' => $report['report']['revision'],
            'entries' => [['student_id' => $this->first->id, 'subject_id' => $this->subject->id, 'score_mode' => 'manual', 'score' => 1, 'notes' => 'ملاحظة لم تُرسل']],
        ])->assertOk();
        DB::table('teacher_assignments')->where('teacher_id', $this->teacher->id)->delete();
        $this->assertSame($original, DB::table('teacher_report_deliveries')->where('id', $delivery->id)->value('snapshot'));
        $this->actingAs($this->first->user)->get($this->deliveryUrl($delivery))->assertOk()
            ->assertSeeText('ملاحظة التقرير الأصلي')->assertDontSeeText('ملاحظة لم تُرسل')->assertDontSeeText('عنوان بعد الإرسال');
    }

    public function test_notifications_use_a_recipient_authorized_delivery_url_without_leaking_other_students_grades(): void
    {
        $report = $this->savedReport(['notes' => 'ملاحظة خاصة لا توضع في الإشعار']);
        $this->send($report, ['student', 'parent', 'admin'], [$this->first->id]);
        foreach ($this->deliveries() as $delivery) {
            $user = User::findOrFail($delivery->recipient_user_id);
            $notification = $user->notifications()->sole();
            $data = $notification->data;
            $this->assertSame('teacher_report', $data['category']);
            $this->assertSame($delivery->id, $data['delivery_id']);
            $this->assertSame($this->deliveryUrl($delivery), parse_url($data['url'], PHP_URL_PATH));
            $this->assertStringNotContainsString('/teacher/', $data['url']);
            $this->assertStringNotContainsString('ملاحظة خاصة', json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->assertArrayNotHasKey('entries', $data);
            $this->assertArrayNotHasKey('grades', $data);
            $this->actingAs($user)->get($data['url'])->assertOk();
        }
    }

    public function test_notification_destinations_cannot_authorize_another_recipients_report_or_a_revoked_parent_link(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student', 'parent'], [$this->first->id]);
        $studentCopy = $this->deliveries($this->first->user)->sole();
        $parentCopy = $this->deliveries($this->sharedParent->user)->sole();
        $resolver = app(NotificationDestination::class);
        $studentData = $this->first->user->notifications()->sole()->data;
        $parentData = $this->sharedParent->user->notifications()->sole()->data;
        $this->assertSame($this->deliveryUrl($studentCopy), $resolver->for($this->first->user, $studentData, false));
        $this->assertSame(route('student.notifications', [], false), $resolver->for($this->second->user, $studentData, false));
        $this->assertSame($this->deliveryUrl($parentCopy), $resolver->for($this->sharedParent->user, $parentData, false));
        $this->assertSame(route('student.notifications', [], false), $resolver->for($this->first->user, array_replace($studentData, ['url' => 'https://external.invalid/received-reports/'.$studentCopy->id]), false));
        $this->sharedParent->students()->detach($this->first);
        $this->assertSame(route('parent.notifications', [], false), $resolver->for($this->sharedParent->user, $parentData, false));
    }

    public function test_parent_inbox_filters_by_the_selected_child_and_opening_a_copy_marks_only_its_own_notification_read(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['parent'], [$this->first->id, $this->second->id]);
        $copies = $this->deliveries($this->sharedParent->user)->keyBy('student_id');
        $firstCopy = $copies[$this->first->id];
        $secondCopy = $copies[$this->second->id];
        foreach ([[$this->first, $firstCopy, $secondCopy], [$this->second, $secondCopy, $firstCopy]] as [$student, $visible, $hidden]) {
            $response = $this->actingAs($this->sharedParent->user)->get('/received-reports?student='.$student->id)->assertOk();
            $this->assertSame([$visible->id], $response->viewData('deliveries')->pluck('id')->all());
            $response->assertSee(route('received-reports.show', $visible->id), false)->assertDontSee(route('received-reports.show', $hidden->id), false);
        }
        $this->get($this->deliveryUrl($firstCopy))->assertOk();
        $this->assertNotNull(DB::table('notifications')->where('id', $firstCopy->id)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $secondCopy->id)->value('read_at'));
        $this->assertNull(DB::table('teacher_report_deliveries')->where('id', $secondCopy->id)->value('read_at'));
    }

    public function test_a_device_push_is_attempted_at_most_once_and_revoked_parent_links_are_rechecked_before_transport(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student', 'parent'], [$this->first->id]);
        $studentCopy = $this->deliveries($this->first->user)->sole();
        $parentCopy = $this->deliveries($this->sharedParent->user)->sole();
        $this->mock(PushNotificationService::class)->shouldReceive('send')->once()
            ->withArgs(fn (User $user, array $payload) => $user->id === $this->first->user_id
                && $payload['delivery_id'] === $studentCopy->id && $payload['notification_id'] === $studentCopy->id
                && $payload['url'] === $this->deliveryUrl($studentCopy) && ! isset($payload['entries']))
            ->andReturn(['fcm_accepted' => 1, 'web_push_accepted' => 0]);
        $this->app->call([new SendTeacherReportPush($studentCopy->id), 'handle']);
        $this->app->call([new SendTeacherReportPush($studentCopy->id), 'handle']);
        $this->assertNotNull(DB::table('teacher_report_deliveries')->where('id', $studentCopy->id)->value('push_attempted_at'));
        $this->sharedParent->students()->detach($this->first);
        $this->app->call([new SendTeacherReportPush($parentCopy->id), 'handle']);
        $this->assertNull(DB::table('teacher_report_deliveries')->where('id', $parentCopy->id)->value('push_attempted_at'));
    }

    public function test_a_device_provider_failure_cannot_remove_the_saved_inbox_report_or_its_notification(): void
    {
        $report = $this->savedReport();
        $this->send($report, ['student'], [$this->first->id]);
        $copy = $this->deliveries($this->first->user)->sole();
        $this->mock(PushNotificationService::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('private device provider error'));
        $this->app->call([new SendTeacherReportPush($copy->id), 'handle']);
        $this->assertDatabaseCount('teacher_report_deliveries', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull(DB::table('teacher_report_deliveries')->where('id', $copy->id)->value('push_attempted_at'));
        $this->actingAs($this->first->user)->get($this->deliveryUrl($copy))->assertOk()->assertDontSee('private device provider error', false);
    }

    public function test_a_queue_dispatch_failure_cannot_turn_a_committed_report_send_into_a_server_error(): void
    {
        $report = $this->savedReport();
        Bus::shouldReceive('dispatch')->once()->withArgs(fn ($job) => $job instanceof SendTeacherReportPush
            && $job->connection === 'database' && $job->queue === 'notifications')
            ->andThrow(new RuntimeException('private queue connection detail'));
        $response = $this->send($report, ['student'], [$this->first->id]);
        $this->assertSame(1, $response['counts']['total']);
        $this->assertDatabaseCount('teacher_report_dispatches', 1);
        $this->assertDatabaseCount('teacher_report_deliveries', 1);
        $this->assertDatabaseCount('notifications', 1);
        $copy = $this->deliveries($this->first->user)->sole();
        $this->actingAs($this->first->user)->get($this->deliveryUrl($copy))->assertOk()->assertDontSee('private queue connection detail', false);
    }

    public function test_received_and_printed_reports_escape_manual_content_and_keep_single_student_scope(): void
    {
        $report = $this->savedReport(['notes' => '<script>deliveryAttack()</script>', 'activity' => '<img src=x onerror=deliveryAttack()>', 'behaviour' => 'calm']);
        $this->send($report, ['student', 'parent'], [$this->first->id]);
        foreach ([$this->first->user, $this->sharedParent->user] as $user) {
            $delivery = $this->deliveries($user)->sole();
            $this->actingAs($user)->get($this->deliveryUrl($delivery))->assertOk()->assertSeeText($this->first->user->name)
                ->assertSee('window.print()', false)->assertDontSeeText($this->second->user->name)->assertDontSee('REPORT-SEND-', false)
                ->assertDontSee('<script>deliveryAttack()', false)->assertDontSee('<img src=x onerror=deliveryAttack()>', false);
        }
    }

    public function test_missing_delivery_tables_fail_with_a_readable_setup_validation_error_instead_of_server_errors(): void
    {
        $report = $this->savedReport();
        Schema::dropIfExists('teacher_report_deliveries');
        Schema::dropIfExists('teacher_report_dispatches');
        $path = '/teacher/reports/'.$report['report']['id'];
        $payload = $this->deliveryPayload($report, ['student'], [$this->first->id]);
        $this->actingAs($this->teacherUser)->getJson($path.'/recipients')->assertUnprocessable();
        $this->postJson($path.'/send-preview', $payload)->assertUnprocessable();
        $this->postJson($path.'/send', $payload + ['request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('notifications', 0);
    }
}
