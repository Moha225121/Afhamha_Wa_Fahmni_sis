<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherNotification;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\TeacherNotificationAudience;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use RuntimeException;
use Tests\Concerns\ConfiguresFcm;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherNotificationTest extends TestCase
{
    use ConfiguresFcm, CreatesStudentEducationFixtures, RefreshDatabase;

    private User $actor;

    private Teacher $teacher;

    private array $a;

    private array $b;

    private array $foreign;

    private User $sibling;

    private Guardian $guardian;

    private $subjectA;

    private $subjectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFcm();
        config(['services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        $this->actor = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        $this->a = $this->createStudentFixture('A');
        $this->b = $this->createStudentFixture('B');
        $this->foreign = $this->createStudentFixture('foreign');
        $this->subjectA = $this->createSubjectFor($this->a['classroom'], 'A');
        $this->subjectB = $this->createSubjectFor($this->b['classroom'], 'B');
        $this->teacher->classrooms()->attach($this->a['classroom']->id, ['subject_id' => $this->subjectA->id]);
        $this->teacher->classrooms()->attach($this->b['classroom']->id, ['subject_id' => $this->subjectB->id]);
        $this->sibling = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $sibling = Student::create(['user_id' => $this->sibling->id, 'student_number' => 'SIBLING', 'classroom_id' => $this->a['classroom']->id, 'status' => 'active']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $this->guardian->students()->attach([$this->a['student']->id, $sibling->id]);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['audience' => 'students', 'title' => 'تنبيه دراسي', 'message' => 'يرجى مراجعة الدرس.'], $overrides);
    }

    private function previewId(array $overrides = []): string
    {
        return $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data($overrides))
            ->assertOk()->viewData('previewId');
    }

    private function send(string $previewId)
    {
        return $this->post('/teacher/notifications', ['preview_id' => $previewId, 'confirmed' => '1']);
    }

    private function recipients(array $filters): array
    {
        return app(TeacherNotificationAudience::class)->resolve($this->teacher, $filters)->pluck('id')->sort()->values()->all();
    }

    private function work(): void
    {
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
    }

    public function test_teacher_can_open_composer_and_sees_only_assigned_subjects_and_classes(): void
    {
        $this->createSubjectFor($this->foreign['classroom'], 'foreign');
        $response = $this->actingAs($this->actor)->get('/teacher/notifications')->assertOk()
            ->assertSee('رسالة جديدة')->assertDontSee('مادة foreign')->assertDontSee($this->foreign['user']->name);
        $this->assertEqualsCanonicalizing([$this->subjectA->id, $this->subjectB->id], $response->viewData('subjects')->pluck('id')->all());
        $expectedClasses = [$this->a['classroom']->id, $this->b['classroom']->id];
        $this->assertEqualsCanonicalizing($expectedClasses, $response->viewData('classrooms')->pluck('id')->all());
        $this->assertEqualsCanonicalizing($expectedClasses, array_column($response->viewData('messagingData')['classes'], 'id'));
    }

    public function test_null_bytes_are_rejected_before_creating_a_postgresql_message(): void
    {
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'students', 'title' => 'QA', 'message' => "unsafe\0text",
        ])->assertRedirect()->assertSessionHasErrors('message');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_all_audiences_resolve_students_and_guardians_within_scope_without_duplicate_guardians(): void
    {
        $students = collect([$this->a['user']->id, $this->b['user']->id, $this->sibling->id])->sort()->values()->all();
        $this->assertSame($students, $this->recipients(['audience' => 'students']));
        $this->assertSame([$this->guardian->user_id], $this->recipients(['audience' => 'guardians']));
        $this->assertCount(4, $this->recipients(['audience' => 'all']));
        $this->assertNotContains($this->foreign['user']->id, $this->recipients(['audience' => 'all']));
    }

    public function test_assigned_subject_and_class_filters_use_the_same_teaching_pair_and_class_curriculum(): void
    {
        $expected = collect([$this->a['user']->id, $this->sibling->id])->sort()->values()->all();
        $this->assertSame($expected, $this->recipients(['audience' => 'students', 'subject_id' => $this->subjectA->id]));
        $this->assertSame($expected, $this->recipients(['audience' => 'students', 'classroom_id' => $this->a['classroom']->id]));
        $this->assertSame([$this->guardian->user_id], $this->recipients(['audience' => 'guardians', 'subject_id' => $this->subjectA->id]));
        $this->a['classroom']->subjects()->detach($this->subjectA);
        $this->assertSame([$this->b['user']->id], $this->recipients(['audience' => 'students']));
    }

    public function test_individual_student_and_guardian_receive_existing_database_notifications_without_devices(): void
    {
        $this->mock(Messaging::class)->shouldNotReceive('sendMulticast');
        foreach ([['student', $this->a['user']], ['guardian', $this->guardian->user]] as [$audience, $user]) {
            $id = $this->previewId(['audience' => $audience, 'recipient_id' => $user->id]);
            $this->send($id)->assertRedirect(route('teacher.notifications.show', $id));
            $this->work();
            $record = $user->notifications()->sole();
            $this->assertSame('teacher_notification', $record->data['category']);
            $this->assertSame($user->role === 'student' ? '/student/notifications' : '/parent/notifications', $record->data['url']);
            $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 1)->assertViewHas('accepted', 0);
            $this->travel(61)->seconds();
        }
        $this->assertSame(0, $this->foreign['user']->notifications()->count());
    }

    public function test_group_jobs_are_encrypted_queued_and_preview_is_not_a_send(): void
    {
        $id = $this->previewId(['audience' => 'all']);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->send($id)->assertRedirect();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 4);
        $this->assertDatabaseCount('job_batches', 1);
        $job = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertStringNotContainsString('يرجى مراجعة الدرس.', $job['data']['command']);
        $this->work();
        $this->assertDatabaseCount('notifications', 4);
        $this->assertSame(1, $this->guardian->user->notifications()->count());
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 4);
    }

    public function test_subject_filtered_group_reaches_only_the_requested_students(): void
    {
        $id = $this->previewId(['subject_id' => $this->subjectB->id]);
        $this->send($id)->assertRedirect();
        $this->work();
        $this->assertSame(1, $this->b['user']->notifications()->count());
        $this->assertSame(0, $this->a['user']->notifications()->count());
        $this->assertSame(0, $this->guardian->user->notifications()->count());
    }

    public function test_guardian_group_deduplicates_siblings_and_inactive_and_soft_deleted_people_are_excluded(): void
    {
        $inactive = User::factory()->create(['role' => 'parent', 'status' => 'inactive']);
        $guardian = Guardian::create(['user_id' => $inactive->id, 'status' => 'active']);
        $guardian->students()->attach($this->a['student']);
        $this->b['student']->delete();
        $this->sibling->update(['status' => 'inactive']);
        $this->assertCount(2, $this->recipients(['audience' => 'all']));
        $id = $this->previewId(['audience' => 'guardians']);
        $this->send($id)->assertRedirect();
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $inactive->notifications()->count());
        $this->guardian->update(['status' => 'inactive']);
        $this->assertSame([$this->a['user']->id], $this->recipients(['audience' => 'all']));
    }

    public function test_inactive_subjects_and_deleted_classes_cannot_expand_scope(): void
    {
        $this->subjectA->update(['status' => 'inactive']);
        $this->assertSame([$this->b['user']->id], $this->recipients(['audience' => 'students']));
        $this->b['classroom']->delete();
        $this->actingAs($this->actor)->get('/teacher/notifications')->assertOk()->assertViewHas('eligibleCount', 0);
    }

    public function test_out_of_scope_recipient_roles_pairs_and_filters_are_rejected(): void
    {
        $admin = $this->createAdmin();
        $invalid = [
            ['audience' => 'everyone'], ['subject_id' => 999999], ['classroom_id' => $this->foreign['classroom']->id],
            ['subject_id' => $this->subjectA->id, 'classroom_id' => $this->b['classroom']->id],
            ['audience' => 'student', 'recipient_id' => $this->foreign['user']->id],
            ['audience' => 'guardian', 'recipient_id' => $admin->id],
            ['audience' => 'guardian', 'recipient_id' => $this->a['user']->id],
            ['audience' => 'students', 'recipient_id' => $this->a['user']->id],
            ['audience' => 'student'], ['teacher_id' => 999], ['user_id' => $admin->id], ['recipient_count' => 1],
            ['audience' => ['students']], ['subject_id' => [$this->subjectA->id]],
        ];
        foreach ($invalid as $changes) {
            $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data($changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_roles_inactive_accounts_and_missing_teacher_profiles_are_denied_on_every_endpoint(): void
    {
        $this->get('/teacher/notifications')->assertRedirect('/login');
        $this->postJson('/teacher/notifications/preview', $this->data())->assertUnauthorized();
        $this->postJson('/teacher/notifications', [])->assertUnauthorized();
        foreach (['admin', 'parent', 'student', 'staff', 'supervisor', 'financial_officer', 'teacher'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/teacher/notifications')->assertForbidden();
            $this->postJson('/teacher/notifications/preview', $this->data())->assertForbidden();
            $this->postJson('/teacher/notifications', [])->assertForbidden();
            $this->get('/teacher/notifications/'.Str::uuid())->assertForbidden();
        }
        $this->actor->update(['status' => 'inactive']);
        $this->actingAs($this->actor)->get('/teacher/notifications')->assertForbidden();
        $this->actor->update(['status' => 'active']);
        $this->teacher->update(['status' => 'inactive']);
        // A real HTTP request reloads its authenticated user and teacher relationship.
        $this->actingAs($this->actor->fresh())->get('/teacher/notifications')->assertForbidden();
    }

    public function test_server_preview_count_changes_expiry_and_cross_teacher_replay_are_protected(): void
    {
        $id = $this->previewId();
        $this->postJson('/teacher/notifications', ['preview_id' => $id, 'confirmed' => 1, 'recipient_count' => 1])->assertUnprocessable();
        $this->postJson('/teacher/notifications', ['preview_id' => $id])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->a['student']->update(['status' => 'inactive']);
        $this->send($id)->assertSessionHasErrors('preview_id');
        $this->travel(11)->minutes();
        $this->send($id)->assertSessionHasErrors('preview_id');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_another_teacher_cannot_use_preview_or_read_delivery_status(): void
    {
        $id = $this->previewId();
        $this->send($id)->assertRedirect();
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        $this->actingAs($other)->postJson('/teacher/notifications', ['preview_id' => $id, 'confirmed' => 1])->assertUnprocessable();
        $this->get(route('teacher.notifications.show', $id))->assertNotFound();
    }

    public function test_confirmation_replay_and_job_retry_do_not_duplicate_database_or_push(): void
    {
        $id = $this->previewId(['audience' => 'student', 'recipient_id' => $this->a['user']->id]);
        $this->send($id)->assertRedirect();
        $this->send($id)->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $job = new SendTeacherNotification($this->actor->id, $id, $this->a['user']->id, ['audience' => 'student', 'recipient_id' => $this->a['user']->id], 'same', 'same');
        $this->app->call([$job, 'handle']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, AuditLog::where('module', 'teacher_notification_delivery')->count());
    }

    public function test_worker_rechecks_assignment_removal_before_delivering(): void
    {
        $id = $this->previewId(['audience' => 'all', 'subject_id' => $this->subjectA->id]);
        $this->send($id)->assertRedirect();
        DB::table('teacher_assignments')->where('teacher_id', $this->teacher->id)->where('subject_id', $this->subjectA->id)->delete();
        $this->work();
        $this->assertDatabaseCount('notifications', 0);
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('skipped', 3);
    }

    public function test_worker_rechecks_teacher_and_recipient_activation_and_never_expands_a_snapshot(): void
    {
        $id = $this->previewId(['audience' => 'all']);
        $this->send($id)->assertRedirect();
        $this->actor->update(['status' => 'inactive']);
        $this->work();
        $this->assertDatabaseCount('notifications', 0);
        $this->actor->update(['status' => 'active']);
        $this->get(route('teacher.notifications.show', $id))->assertViewHas('skipped', 4);
    }

    public function test_multiple_devices_invalid_token_cleanup_and_provider_failure_keep_one_database_row(): void
    {
        $one = $this->tokenFor($this->a['user'], 'teacher-recipient-device-one');
        $bad = $this->tokenFor($this->a['user'], 'expired-teacher-recipient-device');
        $two = $this->tokenFor($this->a['user'], 'teacher-recipient-device-two');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with('token', $one->token), ['name' => 'one']),
            SendReport::failure(MessageTarget::with('token', $bad->token), NotFound::becauseTokenNotFound($bad->token)),
            SendReport::success(MessageTarget::with('token', $two->token), ['name' => 'two']),
        ]));
        $id = $this->previewId(['audience' => 'student', 'recipient_id' => $this->a['user']->id]);
        $this->send($id)->assertRedirect();
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertModelMissing($bad);
        $this->get(route('teacher.notifications.show', $id))->assertViewHas('accepted', 2)->assertViewHas('stored', 1);
    }

    public function test_fcm_outage_preserves_the_database_notification_and_has_no_false_receipt_claim(): void
    {
        $this->tokenFor($this->a['user'], 'outage-device-token');
        $this->mock(Messaging::class)->shouldReceive('sendMulticast')->once()->andThrow(new RuntimeException('SECRET-KEY outage-device-token'));
        $id = $this->previewId(['audience' => 'student', 'recipient_id' => $this->a['user']->id]);
        $this->send($id)->assertRedirect();
        $this->work();
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 1)->assertViewHas('accepted', 0)
            ->assertDontSee('SECRET-KEY')->assertDontSee('outage-device-token');
    }

    public function test_preview_escapes_content_and_hides_device_tokens_and_counts_registered_users_once(): void
    {
        $this->tokenFor($this->a['user'], 'private-teacher-preview-token');
        $this->tokenFor($this->a['user'], 'second-private-teacher-preview-token');
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data(['title' => '<script>alert(1)</script>', 'audience' => 'all']))
            ->assertOk()->assertSee(e('<script>alert(1)</script>'), false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('private-teacher-preview-token')->assertViewHas('registeredCount', 1)->assertViewHas('count', 4);
    }

    public function test_validation_is_arabic_and_malformed_old_input_does_not_break_the_form(): void
    {
        $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data(['title' => '']))
            ->assertUnprocessable()->assertJsonPath('errors.title.0', 'حقل العنوان مطلوب.');
        $this->postJson('/teacher/notifications/preview', $this->data(['message' => str_repeat('ع', 301)]))->assertOk();
        $this->postJson('/teacher/notifications/preview', $this->data(['title' => str_repeat('ع', 61)]))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->withSession(['_old_input' => ['title' => ['bad'], 'message' => ['bad'], 'recipient_id' => ['bad']]])
            ->get('/teacher/notifications?audience=student')->assertOk();
    }

    public function test_no_assignments_and_large_scopes_are_safe_empty_or_require_narrower_filters(): void
    {
        User::factory()->count(100)->create(['role' => 'student', 'status' => 'active'])->each(function ($user, $i): void {
            Student::create(['user_id' => $user->id, 'student_number' => 'MASS-'.$i, 'classroom_id' => $this->a['classroom']->id, 'status' => 'active']);
        });
        $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data())->assertUnprocessable()->assertJsonValidationErrors('audience');
        DB::table('teacher_assignments')->where('teacher_id', $this->teacher->id)->delete();
        $this->get('/teacher/notifications')->assertOk()->assertViewHas('eligibleCount', 0);
        $this->postJson('/teacher/notifications/preview', $this->data())->assertUnprocessable();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_send_preview_and_hourly_limits_are_per_teacher(): void
    {
        $id = $this->previewId();
        for ($batch = 0; $batch < 5; $batch++) {
            $this->send($id)->assertRedirect();
            $this->send($id)->assertRedirect();
            $this->send($id)->assertTooManyRequests();
            $this->travel(61)->seconds();
        }
        $this->send($id)->assertTooManyRequests();
        $this->assertDatabaseCount('job_batches', 1);
        $this->travel(1)->hours();
        for ($i = 0; $i < 20; $i++) {
            $this->post('/teacher/notifications/preview', $this->data())->assertOk();
        }
        $this->post('/teacher/notifications/preview', $this->data())->assertTooManyRequests();
    }

    public function test_both_posts_require_csrf_and_preserve_existing_teacher_middleware(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->actor)->post('/teacher/notifications/preview', $this->data())->assertStatus(419);
        $this->post('/teacher/notifications', ['preview_id' => (string) Str::uuid(), 'confirmed' => 1])->assertStatus(419);
    }

    public function test_recipient_becoming_inactive_after_queueing_is_skipped(): void
    {
        $id = $this->previewId(['audience' => 'students']);
        $this->send($id)->assertRedirect();
        $this->a['user']->update(['status' => 'inactive']);
        $this->work();
        $this->assertSame(0, $this->a['user']->notifications()->count());
        $this->assertDatabaseCount('notifications', 2);
        $this->get(route('teacher.notifications.show', $id))->assertViewHas('skipped', 1)->assertViewHas('stored', 2);
    }

    public function test_new_students_added_after_preview_require_a_new_confirmation(): void
    {
        $id = $this->previewId();
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $user->id, 'student_number' => 'NEW', 'classroom_id' => $this->a['classroom']->id, 'status' => 'active']);
        $this->send($id)->assertSessionHasErrors('preview_id');
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_foreign_guardian_and_partial_pair_assignments_are_not_recipient_shortcuts(): void
    {
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($this->foreign['student']);
        $this->actingAs($this->actor)->postJson('/teacher/notifications/preview', $this->data(['audience' => 'guardian', 'recipient_id' => $parent->id]))->assertUnprocessable();
        $this->b['classroom']->subjects()->attach($this->subjectA);
        $this->postJson('/teacher/notifications/preview', $this->data(['subject_id' => $this->subjectA->id, 'classroom_id' => $this->b['classroom']->id]))->assertUnprocessable();
    }

    public function test_sync_or_cross_database_queue_misconfiguration_is_rejected_before_any_jobs_are_dispatched(): void
    {
        $id = $this->previewId();
        config(['queue.connections.database.driver' => 'sync']);
        $this->send($id)->assertSessionHasErrors('send');
        $this->travel(61)->seconds();
        config(['queue.connections.database.driver' => 'database', 'queue.connections.database.connection' => 'unverified-database']);
        $this->send($id)->assertSessionHasErrors('send');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_database_notification_failure_is_sanitized_before_it_reaches_failed_job_storage(): void
    {
        $this->mock(ParentNotificationService::class)->shouldReceive('sendOnce')->once()->andThrow(new RuntimeException('PRIVATE-SQL-MESSAGE'));
        $job = new SendTeacherNotification($this->actor->id, (string) Str::uuid(), $this->a['user']->id, ['audience' => 'students'], 'title', 'message');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Teacher notification delivery could not be completed.');
        $this->app->call([$job, 'handle']);
    }

    public function test_queue_transaction_failure_rolls_back_and_returns_a_safe_arabic_error(): void
    {
        $id = $this->previewId();
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->module === 'teacher_notifications') {
                throw new RuntimeException('PRIVATE-QUEUE-FAILURE');
            }
        });

        $response = $this->send($id)->assertRedirect(route('teacher.notifications.index'))->assertSessionHasErrors('send');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('job_batches', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        // Carry the real session cookie through the redirect, as a browser does.
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue())
            ->get('/teacher/notifications')->assertOk()->assertSee('تعذّر تأكيد حفظ طلب الإرسال.')
            ->assertDontSee('PRIVATE-QUEUE-FAILURE');
    }

    public function test_one_terminal_delivery_failure_does_not_cancel_the_other_batch_recipients(): void
    {
        $real = app(ParentNotificationService::class);
        $this->mock(ParentNotificationService::class)->shouldReceive('sendOnce')->andReturnUsing(function ($user, $payload, $notificationId) use ($real) {
            if ($user->id === $this->a['user']->id) {
                throw new RuntimeException('private-provider-exception');
            }

            return $real->sendOnce($user, $payload, $notificationId);
        });
        $id = $this->previewId(['audience' => 'all']);
        $this->send($id)->assertRedirect();
        while ($job = Queue::connection('database')->pop('notifications')) {
            try {
                $job->fire();
            } catch (RuntimeException $exception) {
                $this->assertSame('Teacher notification delivery could not be completed.', $exception->getMessage());
                $job->fail($exception);
            }
        }
        $this->get(route('teacher.notifications.show', $id))->assertOk()->assertViewHas('stored', 3)
            ->assertViewHas('failed', 1)->assertViewHas('accepted', 0)->assertDontSee('private-provider-exception');
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertFalse(Bus::findBatch(DB::table('job_batches')->value('id'))->cancelled());
    }

    public function test_recipient_selection_query_count_stays_bounded_at_the_one_hundred_recipient_limit(): void
    {
        $measure = function (): array {
            $this->teacher->unsetRelation('user');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $users = app(TeacherNotificationAudience::class)->resolve($this->teacher, ['audience' => 'students']);
            $users->loadCount(['fcmTokens', 'pushSubscriptions']);
            $result = ['recipients' => $users->count(), 'queries' => count(DB::getQueryLog()), 'milliseconds' => round((hrtime(true) - $start) / 1e6, 3)];
            DB::disableQueryLog();

            return $result;
        };
        $small = $measure();
        User::factory()->count(97)->create(['role' => 'student', 'status' => 'active'])->each(function ($user, $i): void {
            Student::create(['user_id' => $user->id, 'student_number' => 'PERF-'.$i, 'classroom_id' => $this->a['classroom']->id, 'status' => 'active']);
        });
        $large = $measure();
        $this->assertSame(3, $small['recipients']);
        $this->assertSame(100, $large['recipients']);
        $this->assertSame($small['queries'], $large['queries']);
        $this->assertLessThanOrEqual(4, $large['queries']);
        $this->assertDatabaseCount('notifications', 0);
        file_put_contents(sys_get_temp_dir().'/nasr-fullqa-recipient-performance.json', json_encode(['small' => $small, 'large' => $large], JSON_PRETTY_PRINT));
    }
}
