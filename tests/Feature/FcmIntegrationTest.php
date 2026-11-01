<?php

namespace Tests\Feature;

use App\Contracts\FcmAccessTokenProvider;
use App\Models\FcmDevice;
use App\Models\Announcement;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\FcmDestination;
use App\Services\FcmNotificationService;
use App\Services\FcmService;
use App\Services\GoogleFcmAccessTokenProvider;
use App\Services\TeacherCreationIntent;
use App\Jobs\SendFcmNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class FcmIntegrationTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_authenticated_school_roles_can_register_encrypted_tokens_and_only_remove_their_own(): void
    {
        $token = 'fcm-token-'.Str::random(80);
        $student = $this->createStudentFixture('token-user')['user'];

        $this->actingAs($student)->postJson(route('fcm.devices.store'), ['token' => $token])->assertOk();

        $device = FcmDevice::firstOrFail();
        $this->assertNotSame($token, DB::table('fcm_devices')->where('id', $device->id)->value('token'));
        $this->assertSame($token, $device->token);
        $this->assertSame(hash('sha256', $token), $device->token_hash);

        $other = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->actingAs($other)->deleteJson(route('fcm.devices.destroy', $device))->assertNotFound();
        $this->actingAs($student)->deleteJson(route('fcm.devices.destroy', $device))->assertOk();
        $this->assertDatabaseMissing('fcm_devices', ['id' => $device->id]);
    }

    public function test_device_registration_requires_authentication_and_rejects_unsupported_roles(): void
    {
        $this->postJson(route('fcm.devices.store'), ['token' => 'a-valid-shaped-fcm-registration-token-1234567890'])->assertUnauthorized();

        $staff = User::factory()->create(['role' => 'financial_officer', 'status' => 'active']);
        $this->actingAs($staff)->postJson(route('fcm.devices.store'), ['token' => 'a-valid-shaped-fcm-registration-token-1234567890'])->assertForbidden();
    }

    public function test_admin_teacher_and_parent_roles_can_register_devices(): void
    {
        foreach (['admin', 'teacher', 'parent'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            if ($role === 'teacher') {
                Teacher::create(['user_id' => $user->id, 'status' => 'active']);
            } elseif ($role === 'parent') {
                Guardian::create(['user_id' => $user->id, 'relationship' => 'parent', 'status' => 'active']);
            }
            $this->actingAs($user)->postJson(route('fcm.devices.store'), [
                'token' => $role.'-registration-token-'.Str::random(40),
            ])->assertOk();
        }

        $this->assertSame(3, FcmDevice::count());
    }

    public function test_real_access_token_provider_is_bound_without_loading_credentials(): void
    {
        config(['services.firebase.credentials' => null]);
        $provider = app(FcmAccessTokenProvider::class);
        $this->assertInstanceOf(GoogleFcmAccessTokenProvider::class, $provider);
        $this->assertNull($provider->getAccessToken());
        $this->assertInstanceOf(FcmService::class, app(FcmService::class));
    }

    public function test_inactive_school_profiles_cannot_register_devices(): void
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacherUser->id, 'status' => 'inactive']);
        $this->actingAs($teacherUser)->postJson(route('fcm.devices.store'), ['token' => Str::random(64)])->assertForbidden();

        $student = $this->createStudentFixture('inactive-device');
        $student['user']->update(['status' => 'inactive']);
        $this->actingAs($student['user'])->postJson(route('fcm.devices.store'), ['token' => Str::random(64)])->assertForbidden();
    }

    public function test_logout_detaches_only_the_device_registered_in_the_current_session(): void
    {
        $student = $this->createStudentFixture('logout-device')['user'];
        $currentToken = 'current-'.Str::random(64);
        $otherToken = 'other-'.Str::random(64);
        $student->fcmDevices()->create(['token' => $otherToken, 'token_hash' => hash('sha256', $otherToken)]);
        $this->actingAs($student)->postJson(route('fcm.devices.store'), ['token' => $currentToken])->assertOk();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertDatabaseMissing('fcm_devices', ['token_hash' => hash('sha256', $currentToken)]);
        $this->assertDatabaseHas('fcm_devices', ['token_hash' => hash('sha256', $otherToken)]);
    }

    public function test_fcm_http_v1_sends_data_payload_with_a_role_safe_destination(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/second-school-project/messages/123'], 200)]);

        $student = $this->createStudentFixture('fcm-send')['user'];
        $rawToken = 'registration-token-'.Str::random(70);
        $device = $student->fcmDevices()->create(['token' => $rawToken, 'token_hash' => hash('sha256', $rawToken)]);

        app(FcmService::class)->sendToUser($student, ['title' => 'New assignment', 'body' => 'Open the portal', 'url' => 'https://outside.invalid/private']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/projects/second-school-project/messages:send')
            && $request['message']['token'] === $rawToken
            && $request['message']['data']['url'] === route('student.notifications', [], false));
        $this->assertSame($rawToken, $device->fresh()->token);
    }

    public function test_unregistered_fcm_tokens_are_removed_but_retryable_failures_are_not(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        Http::fakeSequence('https://fcm.googleapis.com/*')->push([
            'error' => [
                'status' => 'NOT_FOUND',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ],
        ], 404)->push(['error' => ['status' => 'UNAVAILABLE']], 503);

        $student = $this->createStudentFixture('fcm-invalid')['user'];
        $token = 'expired-token-'.Str::random(80);
        $device = $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        app(FcmService::class)->sendToUser($student, ['title' => 'A notice', 'body' => 'Read it']);
        $this->assertDatabaseMissing('fcm_devices', ['id' => $device->id]);

        $token = 'retry-token-'.Str::random(80);
        $retryDevice = $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        try {
            app(FcmService::class)->sendToUser($student, ['title' => 'A notice', 'body' => 'Read it']);
            $this->fail('Transient FCM errors should be retried by the queue job.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }
        $this->assertDatabaseHas('fcm_devices', ['id' => $retryDevice->id]);
    }

    public function test_retry_skips_devices_already_sent_the_same_event(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        Http::fakeSequence('https://fcm.googleapis.com/*')
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503)->push(['name' => 'second'], 200)->push(['name' => 'first'], 200);

        $student = $this->createStudentFixture('retry-devices')['user'];
        $first = 'first-'.Str::random(64);
        $second = 'second-'.Str::random(64);
        foreach ([$first, $second] as $token) {
            $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        }
        $event = DB::table('fcm_notification_events')->insertGetId([
            'user_id' => $student->id, 'event_key' => 'exam:retry', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['title' => 'Exam', 'body' => 'Published'];
        try {
            app(FcmService::class)->sendToUser($student, $payload, $event);
            $this->fail('A transient failure must cause a retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }
        app(FcmService::class)->sendToUser($student, $payload, $event);
        $tokens = Http::recorded()->map(fn ($pair) => $pair[0]['message']['token'])->all();
        $this->assertSame([$first, $second, $first], $tokens);
        $this->assertSame(2, DB::table('fcm_notification_deliveries')->where('event_id', $event)->count());
    }

    public function test_invalid_argument_does_not_delete_a_potentially_valid_token(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['error' => ['details' => [[
            '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT',
        ]]]], 400)]);
        $student = $this->createStudentFixture('invalid-payload')['user'];
        $token = Str::random(64);
        $device = $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        try {
            app(FcmService::class)->sendToUser($student, ['title' => 'Notice', 'body' => 'Body']);
            $this->fail('Invalid payload errors must be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('400', $exception->getMessage());
        }
        $this->assertDatabaseHas('fcm_devices', ['id' => $device->id]);
    }

    public function test_invalid_response_cannot_remove_a_token_reassigned_during_delivery(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        $student = $this->createStudentFixture('token-old-owner')['user'];
        $other = $this->createStudentFixture('token-new-owner')['user'];
        $token = Str::random(64);
        $device = $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        Http::fake(['https://fcm.googleapis.com/*' => function () use ($device, $other) {
            DB::table('fcm_devices')->where('id', $device->id)->update(['user_id' => $other->id]);
            return Http::response(['error' => ['details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
            ]]]], 404);
        }]);

        app(FcmService::class)->sendToUser($student, ['title' => 'Notice', 'body' => 'Body']);
        $this->assertDatabaseHas('fcm_devices', ['id' => $device->id, 'user_id' => $other->id]);
    }

    public function test_job_uses_database_queue_and_skips_deactivated_accounts(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $student = $this->createStudentFixture('inactive-delivery')['user'];
        $token = Str::random(64);
        $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        $event = DB::table('fcm_notification_events')->insertGetId([
            'user_id' => $student->id, 'event_key' => 'exam:inactive', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student->update(['status' => 'inactive']);
        $job = new SendFcmNotification($student->id, 'exam:inactive', $event, ['title' => 'Exam', 'body' => 'Published']);
        $this->assertSame('database', $job->connection);
        $this->assertTrue($job->afterCommit);
        $job->handle(app(FcmService::class));
        Http::assertNothingSent();
        $this->assertNotNull(DB::table('fcm_notification_events')->where('id', $event)->value('sent_at'));
    }

    public function test_notification_is_queued_only_after_commit_and_rollback_discards_it(): void
    {
        $student = $this->createStudentFixture('after-commit')['user'];
        $token = Str::random(64);
        $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        $service = app(FcmNotificationService::class);
        $payload = ['title' => 'Exam', 'body' => 'Published'];

        try {
            DB::transaction(function () use ($service, $student, $payload): void {
                $service->dispatch($student, 'exam:rolled-back', $payload);
                $this->assertDatabaseCount('jobs', 0);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertDatabaseMissing('fcm_notification_events', ['user_id' => $student->id, 'event_key' => 'exam:rolled-back']);
        $this->assertDatabaseCount('jobs', 0);

        DB::transaction(function () use ($service, $student, $payload): void {
            $service->dispatch($student, 'exam:committed', $payload);
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertDatabaseCount('jobs', 1);
        $service->dispatch($student, 'exam:committed', $payload);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_database_worker_delivers_a_queued_message_using_fake_firebase(): void
    {
        config(['services.firebase.project_id' => 'second-school-project']);
        $this->useFcmAccessToken();
        Http::preventStrayRequests();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'test-message'], 200)]);
        $student = $this->createStudentFixture('queued-delivery')['user'];
        $token = Str::random(64);
        $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        app(FcmNotificationService::class)->dispatch($student, 'exam:queued', ['title' => 'Exam', 'body' => 'Published']);

        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNotNull(DB::table('fcm_notification_events')->where('event_key', 'exam:queued')->value('sent_at'));
    }

    public function test_manual_teacher_notifications_are_limited_to_assigned_class_students_and_guardians(): void
    {
        $owned = $this->createStudentFixture('teacher-owned');
        $foreign = $this->createStudentFixture('teacher-foreign');
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = $this->createSubjectFor($owned['classroom'], 'teacher-owned');
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $owned['classroom']->id,
            'subject_id' => $subject->id,
        ]);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'parent', 'status' => 'active']);
        $guardian->students()->attach($owned['student']->id);

        $submission = (string) Str::uuid();
        $this->actingAs($teacherUser)->post(route('teacher.notifications.store'), [
            'submission_key' => $submission,
            'title' => 'Room reminder',
            'body' => 'Please review the assignment.',
            'audience' => 'students',
            'classroom_id' => $foreign['classroom']->id,
        ])->assertForbidden();

        $this->post(route('teacher.notifications.store'), [
            'submission_key' => $submission,
            'title' => 'Room reminder',
            'body' => 'Please review the assignment.',
            'audience' => 'students',
            'classroom_id' => $owned['classroom']->id,
        ])->assertRedirect();
        $this->assertSame(1, $owned['user']->notifications()->count());
        $this->assertSame(0, $foreign['user']->notifications()->count());

        $this->post(route('teacher.notifications.store'), [
            'submission_key' => (string) Str::uuid(),
            'title' => 'Guardian reminder',
            'body' => 'Please review the assignment.',
            'audience' => 'parents',
            'classroom_id' => $owned['classroom']->id,
        ])->assertRedirect();
        $this->assertSame(1, $parent->notifications()->count());
    }

    public function test_admin_composer_is_idempotent_and_notification_destinations_are_portal_local(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudentFixture('admin-notice')['user'];
        $key = (string) Str::uuid();
        $payload = [
            'submission_key' => $key,
            'title' => 'School notice',
            'body' => 'Please read the update.',
            'audience' => 'students',
        ];

        $this->actingAs($admin)->post(route('admin.notifications.store'), $payload)->assertRedirect();
        $this->post(route('admin.notifications.store'), $payload)->assertRedirect();

        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(route('student.notifications', [], false), $student->notifications()->firstOrFail()->data['url']);
        $this->assertSame('/parent/results', FcmDestination::forUser(User::factory()->make(['role' => 'parent']), '/parent/results'));
        $this->assertSame(route('teacher.dashboard', [], false), FcmDestination::forUser(User::factory()->make(['role' => 'teacher']), '/admin/results'));
        $this->assertSame(route('student.notifications', [], false), FcmDestination::forUser($student, '/student/../admin/users'));
    }

    public function test_classroom_event_notifications_have_duplicate_prevention_for_students_and_parents(): void
    {
        $student = $this->createStudentFixture('class-event');
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'parent', 'status' => 'active']);
        $guardian->students()->attach($student['student']->id);

        $service = app(FcmNotificationService::class);
        $message = ['title' => 'Exam notice', 'body' => 'New exam published.'];
        $service->notifyClassroom('exam:55', $student['classroom']->id, $message);
        $service->notifyClassroom('exam:55', $student['classroom']->id, $message);

        $this->assertSame(1, $student['user']->notifications()->count());
        $this->assertSame(1, $parent->notifications()->count());
        $this->assertSame(route('student.assignments.index', [], false), $student['user']->notifications()->firstOrFail()->data['url']);
        $this->assertSame(route('parent.assignments', [], false), $parent->notifications()->firstOrFail()->data['url']);
    }

    public function test_admin_can_address_other_administrators(): void
    {
        $sender = $this->createAdmin();
        $recipient = $this->createAdmin();
        $this->actingAs($sender)->post(route('admin.notifications.store'), [
            'submission_key' => (string) Str::uuid(), 'title' => 'Notice', 'body' => 'For the administration',
            'audience' => 'administrators',
        ])->assertRedirect();
        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame(route('admin.notifications.index', [], false), $recipient->notifications()->firstOrFail()->data['url']);
    }

    public function test_teacher_scheduled_exam_notifies_its_class_only_once(): void
    {
        $student = $this->createStudentFixture('scheduled-exam');
        $other = $this->createStudentFixture('other-exam-class');
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = $this->createSubjectFor($student['classroom'], 'scheduled-exam');
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $student['classroom']->id, 'subject_id' => $subject->id]);
        $intent = app(TeacherCreationIntent::class)->issue($teacher->id, 'exams');
        $payload = [
            'creation_token' => $intent, 'title' => 'Scheduled exam', 'classroom_id' => $student['classroom']->id,
            'subject_id' => $subject->id, 'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'duration_minutes' => 30, 'status' => 'scheduled', 'questions' => [[
                'type' => 'mcq', 'text' => '2 + 2?', 'score' => 10,
                'choices' => [['text' => '4', 'is_correct' => true], ['text' => '3', 'is_correct' => false]],
            ]],
        ];
        $this->actingAs($teacherUser)->post(route('teacher.exams.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('teacher.exams.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $student['user']->notifications()->count());
        $this->assertSame(0, $other['user']->notifications()->count());
        $this->assertSame(route('student.exams.index', [], false), $student['user']->notifications()->firstOrFail()->data['url']);
    }

    public function test_admin_exam_publication_notifies_the_class_once(): void
    {
        $student = $this->createStudentFixture('admin-exam-publish');
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = $this->createSubjectFor($student['classroom'], 'admin-exam-publish');
        $examId = DB::table('exams')->insertGetId([
            'title' => 'Midterm exam',
            'subject_id' => $subject->id,
            'classroom_id' => $student['classroom']->id,
            'teacher_id' => $teacher->id,
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'total_score' => 100,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->createAdmin())->patch(route('admin.exams.status', $examId), ['status' => 'scheduled'])->assertRedirect();
        $this->assertSame(1, $student['user']->notifications()->count());
        $this->patch(route('admin.exams.status', $examId), ['status' => 'published'])->assertRedirect();
        $this->patch(route('admin.exams.status', $examId), ['status' => 'published'])->assertRedirect();

        $this->assertSame(1, $student['user']->notifications()->count());
        $this->assertSame('academic', $student['user']->notifications()->firstOrFail()->data['category']);
    }

    public function test_scheduled_announcements_are_caught_up_after_scheduler_downtime(): void
    {
        $student = $this->createStudentFixture('late-announcement')['user'];
        DB::table('fcm_schedule_cursors')->where('name', 'announcements')->update(['checked_through' => now()->subMinutes(30)]);
        Announcement::create([
            'title' => 'Scheduled notice', 'content' => 'Hello', 'audience' => 'students',
            'status' => 'published', 'published_at' => now()->subMinutes(10),
            'created_by' => $this->createAdmin()->id,
        ]);

        $this->artisan('announcements:notify-due')->assertSuccessful();
        $this->artisan('announcements:notify-due')->assertSuccessful();
        $this->assertSame(1, $student->notifications()->count());
    }

    private function useFcmAccessToken(): void
    {
        $this->app->instance(FcmAccessTokenProvider::class, new class implements FcmAccessTokenProvider
        {
            public function getAccessToken(): ?string
            {
                return 'safe-test-access-token';
            }
        });
    }
}
