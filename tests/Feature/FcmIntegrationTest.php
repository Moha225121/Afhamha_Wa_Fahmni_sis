<?php

namespace Tests\Feature;

use App\Contracts\FcmAccessTokenProvider;
use App\Models\FcmDevice;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\FcmDestination;
use App\Services\FcmNotificationService;
use App\Services\FcmService;
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
            $this->actingAs($user)->postJson(route('fcm.devices.store'), [
                'token' => $role.'-registration-token-'.Str::random(40),
            ])->assertOk();
        }

        $this->assertSame(3, FcmDevice::count());
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

        $this->actingAs($this->createAdmin())->patch(route('admin.exams.status', $examId), ['status' => 'published'])->assertRedirect();
        $this->patch(route('admin.exams.status', $examId), ['status' => 'published'])->assertRedirect();

        $this->assertSame(1, $student['user']->notifications()->count());
        $this->assertSame('academic', $student['user']->notifications()->firstOrFail()->data['category']);
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
