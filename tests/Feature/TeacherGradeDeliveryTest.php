<?php

namespace Tests\Feature;

use App\Jobs\SendGradeSheetPush;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\GradeSheetNotificationService;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherGradeDeliveryTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $fixture;

    private User $teacherUser;

    private Teacher $teacher;

    private User $parentUser;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nasbia.teacher_sheet_notifications' => true, 'nasbia.teacher_sheet_visibility' => true]);
        $this->dispatcher = Bus::getFacadeRoot();
        Bus::fake([SendGradeSheetPush::class]);
        $this->mock(PushNotificationService::class)->shouldReceive('send')->andReturn([
            'fcm_accepted' => 0, 'web_push_accepted' => 0,
        ])->byDefault();
        $this->fixture = $this->createStudentFixture('GRADE-DELIVERY');
        $subject = $this->createSubjectFor($this->fixture['classroom'], 'GRADE-DELIVERY');
        $this->teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->teacher->classrooms()->attach($this->fixture['classroom'], ['subject_id' => $subject->id]);
        $this->parentUser = $this->guardianFor($this->fixture['student']);
    }

    private function guardianFor(Student $student, string $userStatus = 'active', string $guardianStatus = 'active', string $role = 'parent'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => $userStatus]);
        $guardian = Guardian::create(['user_id' => $user->id, 'status' => $guardianStatus]);
        $guardian->students()->attach($student);

        return $user;
    }

    private function payload(float $score = 16, float $average = 80): array
    {
        $studentId = $this->fixture['student']->id;

        return [
            'classroom_id' => $this->fixture['classroom']->id,
            'scores' => [$studentId => $average],
            'sheet_columns' => [
                ['key' => 'monthly', 'title' => 'اختبار رياضيات شهري', 'max_score' => 20],
                ['key' => 'activity', 'title' => 'نشاط رياضيات', 'max_score' => 10],
            ],
            'column_scores' => ['monthly' => [$studentId => $score]],
        ];
    }

    private function save(array $payload): void
    {
        $this->actingAs($this->teacherUser)->postJson(route('teacher.grades.store'), $payload)->assertOk();
    }

    public function test_nisbiya_default_policy_saves_marks_without_publishing_or_notifying(): void
    {
        config(['nasbia.teacher_sheet_notifications' => false, 'nasbia.teacher_sheet_visibility' => false]);
        $this->actingAs($this->teacherUser)->get(route('teacher.grades.index'))->assertOk()
            ->assertSeeText('حفظ الدرجات')->assertDontSeeText('حفظ وإرسال الدرجات')
            ->assertDontSeeText('عند الحفظ تصل الدرجات');
        $this->save($this->payload());
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->actingAs($this->fixture['user'])->get(route('student.results'))->assertOk()->assertDontSeeText('اختبار رياضيات شهري');
        $this->actingAs($this->parentUser)->get(route('parent.results', ['student' => $this->fixture['student']->id]))
            ->assertOk()->assertDontSeeText('اختبار رياضيات شهري');
        Bus::assertNotDispatched(SendGradeSheetPush::class);
    }

    public function test_saving_a_grade_delivers_the_score_and_notification_to_student_and_all_linked_active_parents(): void
    {
        $secondParent = $this->guardianFor($this->fixture['student']);
        $this->save($this->payload());

        foreach ([$this->fixture['user'], $this->parentUser, $secondParent] as $user) {
            $notification = $user->notifications()->sole();
            $this->assertSame(ParentPortalNotification::class, $notification->type);
            $this->assertNull($notification->read_at);
            $this->assertSame('grade', $notification->data['category']);
            $this->assertSame($this->fixture['student']->id, $notification->data['student_id']);
            $this->assertSame($this->teacher->id, $notification->data['teacher_id']);
            $this->assertSame($this->fixture['classroom']->id, $notification->data['classroom_id']);
            $grade = collect($notification->data['grades'])->firstWhere('key', 'monthly');
            $this->assertSame('اختبار رياضيات شهري', $grade['title']);
            $this->assertEquals(16, $grade['score']);
            $this->assertEquals(20, $grade['maximum_score']);
            $this->assertStringContainsString('16 من 20', $notification->data['body']);
            $this->assertStringNotContainsString('%', $notification->data['body']);
            $this->assertArrayNotHasKey('average_score', $notification->data);
            $this->assertSame(
                $user->role === 'student' ? route('student.results', [], false) : route('parent.results', ['student' => $this->fixture['student']->id], false),
                $notification->data['url'],
            );
        }
        Bus::assertDispatched(SendGradeSheetPush::class, fn ($job) => $job->afterCommit === true);
    }

    public function test_saving_the_same_sheet_again_does_not_duplicate_notifications(): void
    {
        $this->save($this->payload());
        $this->save($this->payload());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('grade_sheets', 1);
    }

    public function test_changed_columns_with_the_same_average_notify_once_and_reverting_sends_a_fresh_notification(): void
    {
        $original = $this->payload();
        $this->save($original);
        $changed = $this->payload(15);
        $changed['column_scores']['activity'] = [$this->fixture['student']->id => 7];
        $this->save($changed);
        $this->assertSame(2, $this->fixture['user']->notifications()->count());
        $this->assertSame(2, $this->parentUser->notifications()->count());
        $this->save($original);
        $this->assertSame(3, $this->fixture['user']->notifications()->count());
    }

    public function test_new_columns_notify_even_when_the_aggregate_does_not_change(): void
    {
        $this->save($this->payload());
        $payload = $this->payload();
        $payload['column_scores']['activity'] = [$this->fixture['student']->id => 8];
        $this->save($payload);
        $this->assertSame(2, $this->parentUser->notifications()->count());
    }

    public function test_zero_and_fractional_scores_are_delivered_but_aggregate_only_changes_are_silent(): void
    {
        foreach ([0, 0.5, 1] as $score) {
            $before = $this->fixture['user']->notifications()->pluck('id');
            $this->save($this->payload($score, $score));
            $data = $this->fixture['user']->notifications()->whereNotIn('id', $before)->sole()->data;
            $this->assertEquals($score, collect($data['grades'])->firstWhere('key', 'monthly')['score']);
            $this->assertArrayNotHasKey('average_score', $data);
        }
        $aggregate = $this->payload(1, 72);
        unset($aggregate['column_scores']);
        $this->save($aggregate);
        $this->assertSame(3, $this->fixture['user']->notifications()->count());
        $saved = json_decode(DB::table('grade_sheets')->value('scores'), true);
        $this->assertEquals(72, $saved[$this->fixture['student']->id]);
    }

    public function test_one_student_change_does_not_notify_unchanged_classmates_or_unrelated_parents(): void
    {
        $peer = $this->createStudentFixture('GRADE-PEER');
        $peer['student']->update(['classroom_id' => $this->fixture['classroom']->id]);
        $peerParent = $this->guardianFor($peer['student']);
        $outside = $this->createStudentFixture('GRADE-OUTSIDE');
        $outsideParent = $this->guardianFor($outside['student']);
        $payload = $this->payload();
        $payload['scores'][$peer['student']->id] = 70;
        $payload['column_scores']['monthly'][$peer['student']->id] = 14;
        $this->save($payload);
        $payload['scores'][$this->fixture['student']->id] = 90;
        $payload['column_scores']['monthly'][$this->fixture['student']->id] = 18;
        $this->save($payload);
        $this->assertSame(2, $this->parentUser->notifications()->count());
        $this->assertSame(1, $peerParent->notifications()->count());
        $this->assertSame(1, $peer['user']->notifications()->count());
        $this->assertSame(0, $outsideParent->notifications()->count());
        $this->assertSame(0, $outside['user']->notifications()->count());
    }

    public function test_aggregate_only_initial_save_creates_no_notification(): void
    {
        $payload = $this->payload();
        unset($payload['column_scores']);
        $this->save($payload);
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNotDispatched(SendGradeSheetPush::class);
    }

    public function test_inactive_guardians_accounts_and_non_parent_accounts_are_excluded(): void
    {
        $excluded = [
            $this->guardianFor($this->fixture['student'], 'inactive'),
            $this->guardianFor($this->fixture['student'], 'active', 'inactive'),
            $this->guardianFor($this->fixture['student'], 'active', 'active', 'teacher'),
        ];
        $this->save($this->payload());
        foreach ($excluded as $user) {
            $this->assertSame(0, $user->notifications()->count());
        }
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_missing_guardian_does_not_prevent_saving_or_student_notification(): void
    {
        $this->fixture['student']->guardians()->detach();
        $this->save($this->payload());
        $this->assertSame(1, $this->fixture['user']->notifications()->count());
        $this->assertSame(0, $this->parentUser->notifications()->count());
        $this->assertDatabaseCount('grade_sheets', 1);
    }

    public function test_inactive_student_receives_no_grade_notifications_and_does_not_notify_parents(): void
    {
        $this->fixture['student']->update(['status' => 'inactive']);
        $this->save($this->payload());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_inactive_student_account_does_not_receive_or_send_parent_grade_notifications(): void
    {
        $this->fixture['user']->update(['status' => 'inactive']);
        $this->save($this->payload());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_invalid_or_out_of_scope_scores_cannot_save_or_send_notifications(): void
    {
        $outside = $this->createStudentFixture('GRADE-FORBIDDEN');
        $invalid = $this->payload(21);
        $this->actingAs($this->teacherUser)->postJson(route('teacher.grades.store'), $invalid)->assertUnprocessable();
        $unknownColumn = $this->payload();
        $unknownColumn['column_scores']['not_a_sheet_column'] = [$this->fixture['student']->id => 5];
        $this->postJson(route('teacher.grades.store'), $unknownColumn)->assertUnprocessable();
        $foreign = $this->payload();
        $foreign['column_scores']['monthly'][$outside['student']->id] = 10;
        $this->postJson(route('teacher.grades.store'), $foreign)->assertForbidden();
        $foreign = $this->payload();
        $foreign['classroom_id'] = $outside['classroom']->id;
        $this->postJson(route('teacher.grades.store'), $foreign)->assertForbidden();
        $this->assertDatabaseCount('grade_sheets', 0);
        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNotDispatched(SendGradeSheetPush::class);
    }

    public function test_other_roles_cannot_save_teacher_grades(): void
    {
        foreach ([$this->fixture['user'], $this->parentUser] as $user) {
            $this->actingAs($user)->postJson(route('teacher.grades.store'), $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('grade_sheets', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_student_and_parent_results_show_the_test_title_and_raw_score(): void
    {
        $this->save($this->payload(16.5, 82.5));
        $this->actingAs($this->fixture['user'])->get(route('student.results'))
            ->assertOk()->assertSeeText('اختبار رياضيات شهري')->assertSeeText('16.5')->assertSeeText('20');
        $this->actingAs($this->parentUser)->get(route('parent.results', ['student' => $this->fixture['student']->id]))
            ->assertOk()->assertSeeText('اختبار رياضيات شهري')->assertSeeText('16.5')->assertSeeText('20');
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_push_transport_failure_cannot_undo_saved_grades_or_database_notifications(): void
    {
        $this->save($this->payload());
        $this->mock(PushNotificationService::class)->shouldReceive('send')->andThrow(new RuntimeException('transport unavailable'));
        $jobs = Bus::dispatched(SendGradeSheetPush::class);
        $this->assertNotEmpty($jobs);
        foreach ($jobs as $job) {
            try {
                $this->app->call([$job, 'handle']);
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('transport unavailable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->save($this->payload());
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_sync_push_exception_does_not_fail_the_http_save(): void
    {
        Bus::swap($this->dispatcher);
        config(['queue.default' => 'sync']);
        $this->mock(PushNotificationService::class)->shouldReceive('send')->twice()
            ->andThrow(new RuntimeException('private transport detail'));
        $this->save($this->payload());
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertDatabaseCount('notifications', 2);
        $saved = json_decode(DB::table('grade_sheets')->value('column_scores'), true);
        $this->assertEquals(16, $saved['monthly'][$this->fixture['student']->id]);
    }

    public function test_notification_storage_failure_rolls_back_the_grade_transaction(): void
    {
        $this->save($this->payload());
        $before = (array) DB::table('grade_sheets')->first();
        $this->mock(GradeSheetNotificationService::class)->shouldReceive('publish')->once()
            ->andThrow(new RuntimeException('simulated notification storage failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->teacherUser)->postJson(route('teacher.grades.store'), $this->payload(18, 90));
            $this->fail('Expected notification storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated notification storage failure', $exception->getMessage());
        }
        $this->assertSame($before, (array) DB::table('grade_sheets')->first());
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_changed_test_title_or_maximum_score_updates_the_notification_with_unchanged_raw_grade(): void
    {
        $this->save($this->payload());
        $payload = $this->payload();
        $payload['sheet_columns'][0]['title'] = 'اختبار رياضيات مصحح';
        $this->save($payload);
        $this->assertSame(2, $this->fixture['user']->notifications()->count());
        $before = $this->fixture['user']->notifications()->pluck('id');
        $payload['sheet_columns'][0]['max_score'] = 25;
        $this->save($payload);
        $data = $this->fixture['user']->notifications()->whereNotIn('id', $before)->sole()->data;
        $grade = collect($data['grades'])->firstWhere('key', 'monthly');
        $this->assertSame('اختبار رياضيات مصحح', $grade['title']);
        $this->assertEquals(16, $grade['score']);
        $this->assertEquals(25, $grade['maximum_score']);
        $this->assertSame(3, $this->parentUser->notifications()->count());
    }

    public function test_nullable_column_score_map_is_safely_ignored(): void
    {
        $payload = $this->payload();
        $payload['column_scores']['activity'] = null;
        $this->save($payload);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_legacy_scalar_sheet_metadata_and_score_maps_do_not_break_saving(): void
    {
        $this->save($this->payload());
        DB::table('grade_sheets')->update(['sheet_data' => '42', 'column_scores' => '{"monthly":42}']);
        $this->save($this->payload(17));
        $this->assertDatabaseCount('grade_sheets', 1);
        $this->assertDatabaseCount('notifications', 4);
    }
}
