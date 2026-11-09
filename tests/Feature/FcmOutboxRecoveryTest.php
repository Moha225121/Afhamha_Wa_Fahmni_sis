<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\TeacherCreationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class FcmOutboxRecoveryTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    public static function sources(): array
    {
        return ['scheduled exam' => ['exams'], 'assignment' => ['assignments'], 'manual notice' => ['notifications']];
    }

    #[DataProvider('sources')]
    public function test_queue_failure_preserves_source_and_pending_delivery_then_scheduler_recovers_once(string $kind): void
    {
        $fixture = $this->createStudentFixture('OUTBOX-'.$kind);
        $subject = $this->createSubjectFor($fixture['classroom'], 'OUTBOX-'.$kind);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subject->id]);
        $token = 'QA-outbox-device-'.Str::random(60);
        $fixture['user']->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        $payload = ['title' => 'QA source survives queue outage', 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subject->id];
        if ($kind === 'exams') {
            $payload += ['creation_token' => app(TeacherCreationIntent::class)->issue($teacher->id, $kind),
                'starts_at' => now()->addHour()->format('Y-m-d\TH:i'), 'duration_minutes' => 30, 'status' => 'scheduled',
                'questions' => [['type' => 'short_answer', 'text' => 'QA explain', 'score' => 10]]];
        } elseif ($kind === 'assignments') {
            $payload += ['creation_token' => app(TeacherCreationIntent::class)->issue($teacher->id, $kind),
                'due_date' => now()->addDay()->toDateString(), 'max_score' => 10];
        } else {
            $payload += ['submission_key' => (string) Str::uuid(), 'body' => 'QA durable notice', 'audience' => 'students'];
        }
        $actualQueue = Queue::connection('database');
        $failedQueue = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $failedQueue->shouldReceive('push')->once()->andThrow(new RuntimeException('QA simulated queue insert failure'));
        Queue::shouldReceive('connection')->with('database')->andReturn($failedQueue, $actualQueue);

        $this->actingAs($teacher->user)->post(route('teacher.'.$kind.'.store'), $payload)->assertRedirect()
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'بانتظار إعادة محاولة التسليم'));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('fcm_notification_events', 1);
        $this->assertDatabaseCount('jobs', 0);
        $event = DB::table('fcm_notification_events')->first();
        $this->assertNotNull($event->payload);
        $this->assertNull($event->queued_at);
        $this->assertNull($event->sent_at);
        if ($kind !== 'notifications') {
            $this->assertDatabaseCount($kind, 1);
        }

        $this->artisan('fcm:retry-pending')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull(DB::table('fcm_notification_events')->value('queued_at'));
        $this->post(route('teacher.'.$kind.'.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->artisan('fcm:retry-pending')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 1);
        if ($kind !== 'notifications') {
            $this->assertDatabaseCount($kind, 1);
        }
    }

    public function test_additive_migration_preserves_legacy_events_and_never_invents_their_payload(): void
    {
        $user = $this->createStudentFixture('OUTBOX-UPGRADE')['user'];
        $migration = require database_path('migrations/2026_11_08_000001_add_fcm_outbox_payload.php');
        $migration->down();
        $id = DB::table('fcm_notification_events')->insertGetId(['user_id' => $user->id, 'event_key' => 'legacy:qa',
            'sent_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('fcm_notification_events')->find($id);
        $migration->up();
        $this->assertTrue(Schema::hasColumns('fcm_notification_events', ['payload', 'queued_at']));
        $after = DB::table('fcm_notification_events')->find($id);
        $this->assertSame($before->event_key, $after->event_key);
        $this->assertSame($before->created_at, $after->created_at);
        $this->assertNull($after->payload);
        $this->assertNull($after->queued_at);
        $this->assertSame(0, app(FcmNotificationService::class)->retryPending());
        $this->assertDatabaseCount('jobs', 0);
        $migration->down();
        $this->assertSame($before->event_key, DB::table('fcm_notification_events')->find($id)->event_key);
        $this->assertFalse(Schema::hasColumn('fcm_notification_events', 'payload'));
        $migration->up();
    }

    public function test_mismatched_queue_database_stays_pending_without_resolving_another_connection(): void
    {
        $user = $this->subscribedStudent('OUTBOX-CONNECTION');
        config(['queue.connections.database.connection' => 'unapproved_external_connection']);
        Queue::shouldReceive('connection')->never();
        $service = app(FcmNotificationService::class);
        $service->record($user, 'qa:mismatched-db', ['title' => 'QA notice', 'body' => 'QA body']);
        $this->assertTrue($service->hasPendingDelivery());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull(DB::table('fcm_notification_events')->value('queued_at'));
    }

    public function test_failure_after_actual_queue_insert_rolls_back_job_and_marker_then_retry_succeeds(): void
    {
        $user = $this->subscribedStudent('OUTBOX-ATOMIC');
        $actualQueue = Queue::connection('database');
        $failedQueue = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $failedQueue->shouldReceive('push')->once()->andReturnUsing(function ($job) use ($actualQueue): void {
            $actualQueue->push($job);
            $this->assertDatabaseCount('jobs', 1);
            throw new RuntimeException('QA failure after inserting the queue record');
        });
        Queue::shouldReceive('connection')->with('database')->andReturn($failedQueue, $actualQueue);
        $service = app(FcmNotificationService::class);
        $service->record($user, 'qa:atomic', ['title' => 'QA notice', 'body' => 'QA body']);
        $this->assertTrue($service->hasPendingDelivery());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull(DB::table('fcm_notification_events')->value('queued_at'));
        $this->assertSame(1, $service->retryPending());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull(DB::table('fcm_notification_events')->value('queued_at'));
    }

    public function test_source_rollback_discards_notification_event_and_job(): void
    {
        $user = $this->subscribedStudent('OUTBOX-ROLLBACK');
        $service = app(FcmNotificationService::class);
        try {
            DB::transaction(function () use ($service, $user): void {
                $service->record($user, 'qa:rollback', ['title' => 'QA notice', 'body' => 'QA body']);
                $this->assertDatabaseCount('notifications', 1);
                $this->assertDatabaseCount('fcm_notification_events', 1);
                $this->assertDatabaseCount('jobs', 0);
                throw new RuntimeException('QA rollback source');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('QA rollback source', $exception->getMessage());
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('fcm_notification_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function subscribedStudent(string $suffix): User
    {
        $user = $this->createStudentFixture($suffix)['user'];
        $token = 'QA-outbox-device-'.Str::random(60);
        $user->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);

        return $user;
    }
}
