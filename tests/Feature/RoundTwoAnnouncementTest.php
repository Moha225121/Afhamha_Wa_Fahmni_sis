<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\FcmDevice;
use App\Models\Guardian;
use App\Models\User;
use App\Jobs\SendFcmNotification;
use App\Services\FcmNotificationService;
use App\Services\FcmService;
use App\Services\StudentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class RoundTwoAnnouncementTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    public function test_school_local_publication_time_is_stored_as_an_instant_and_round_trips(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $admin = $this->createAdmin();
        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'QA local schedule', 'content' => 'School notice', 'audience' => 'all', 'status' => 'published',
            'published_at' => '2026-11-11T12:05', 'expires_at' => '2026-11-12T00:30', 'schedule_timezone' => 'Africa/Tripoli',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $notice = Announcement::sole();
        $this->assertSame('2026-11-11 10:05:00', $notice->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 22:30:00', $notice->expires_at->format('Y-m-d H:i:s'));
        $this->get(route('admin.announcements.edit', $notice))->assertOk()
            ->assertSee('2026-11-11T12:05', false)->assertSee('2026-11-12T00:30', false);
    }

    public function test_admin_combines_search_audience_and_state_filters(): void
    {
        $admin = $this->createAdmin();
        foreach ([['أولياء QA', 'parents', 'draft'], ['أولياء hidden', 'students', 'draft'], ['Other QA', 'parents', 'published']] as [$title, $audience, $status]) {
            Announcement::create(compact('title', 'audience', 'status') + ['content' => 'Notice', 'created_by' => $admin->id]);
        }
        $this->actingAs($admin)->get(route('admin.announcements.index', ['search' => 'أولياء', 'audience' => 'parents', 'status' => 'draft']))
            ->assertOk()->assertSeeText('أولياء QA')->assertDontSeeText('أولياء hidden')->assertDontSeeText('Other QA');
        $this->get(route('admin.announcements.index', ['search' => 'no-match']))->assertOk()->assertSeeText('لا توجد إعلانات مطابقة.');
        $this->get(route('admin.announcements.index'))->assertOk()->assertSeeText('Other QA');
    }

    public function test_immediate_and_scheduled_announcements_reach_only_the_target_at_the_utc_boundary_once(): void
    {
        $this->travelTo('2026-11-11 21:59:00');
        $child = $this->createStudentFixture('R2-SCHEDULE');
        $other = $this->createStudentFixture('R2-OTHER');
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'parent', 'status' => 'active']);
        $guardian->students()->attach($child['student']->id);
        DB::table('fcm_schedule_cursors')->where('name', 'announcements')->update(['checked_through' => now()]);
        $admin = $this->createAdmin();
        $data = ['content' => 'QA details', 'audience' => 'all', 'classroom_id' => $child['classroom']->id, 'status' => 'published'];
        $this->actingAs($admin)->post(route('admin.announcements.store'), ['title' => 'Immediate QA'] + $data)->assertRedirect()->assertSessionHasNoErrors();
        $immediate = Announcement::where('title', 'Immediate QA')->firstOrFail();
        $this->assertSame(1, $parent->notifications()->count());
        $this->assertSame(1, $child['user']->notifications()->count());
        $this->assertSame(0, $other['user']->notifications()->count());
        $this->actingAs($other['user'])->get(route('student.announcements.show', $immediate))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'Scheduled QA', 'published_at' => '2026-11-12T00:00', 'expires_at' => '2026-11-12T00:02',
            'schedule_timezone' => 'Africa/Tripoli',
        ] + $data)->assertRedirect()->assertSessionHasNoErrors();
        $scheduled = Announcement::where('title', 'Scheduled QA')->firstOrFail();
        $this->assertSame('2026-11-11 22:00:00', $scheduled->published_at->format('Y-m-d H:i:s'));
        $this->actingAs($parent)->get(route('parent.announcements.show', $scheduled))->assertNotFound();
        $this->actingAs($child['user'])->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->travelTo('2026-11-11 22:00:00');
        $this->artisan('announcements:notify-due')->assertSuccessful();
        $this->artisan('announcements:notify-due')->assertSuccessful();
        $this->assertSame(2, $parent->notifications()->count());
        $this->assertSame(2, $child['user']->notifications()->count());
        $this->assertSame(0, $other['user']->notifications()->count());
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 2);
        $this->actingAs($parent)->get(route('parent.announcements.show', $scheduled))->assertOk()->assertSeeText('Scheduled QA');
        $this->travelTo('2026-11-11 22:02:00');
        $this->get(route('parent.announcements.show', $scheduled))->assertNotFound();
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->assertSame(2, $parent->notifications()->count(), 'Expired notification history remains stored.');
    }

    public function test_date_validation_preserves_local_input_and_legacy_absolute_requests(): void
    {
        $this->actingAs($this->createAdmin());
        $data = ['title' => 'QA', 'content' => 'Notice', 'audience' => 'parents', 'status' => 'draft'];
        $this->post(route('admin.announcements.store'), $data + [
            'published_at' => '2026-11-11T12:05', 'expires_at' => '2026-11-11T11:05', 'schedule_timezone' => 'Africa/Tripoli',
        ])->assertSessionHasErrors('expires_at')->assertSessionHasInput('published_at', '2026-11-11T12:05');
        $this->assertDatabaseCount('announcements', 0);
        $this->post(route('admin.announcements.store'), $data + ['published_at' => '2026-11-11T12:05:00+02:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-11 10:05:00', Announcement::sole()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_draft_deletion_and_archive_preserve_delivered_history_and_reject_non_admins(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudentFixture('R2-ARCHIVE');
        $draft = $this->notice($admin, ['status' => 'draft']);
        $this->actingAs($student['user'])->delete(route('admin.announcements.destroy', $draft))->assertForbidden();
        $this->patch(route('admin.announcements.archive', $draft))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.announcements.destroy', $draft))->assertRedirect()->assertSessionHasNoErrors();
        $this->delete(route('admin.announcements.destroy', $draft))->assertNotFound();
        $published = $this->notice($admin);
        app(StudentNotificationService::class)->sendAnnouncement($published);
        $this->delete(route('admin.announcements.destroy', $published))->assertSessionHasErrors('announcement');
        foreach ([1, 2] as $_) {
            $this->patch(route('admin.announcements.archive', $published))->assertRedirect();
        }
        $this->assertSame('archived', $published->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('module', 'announcements')->where('action', 'archived')->where('record_id', $published->id)->count());
        $this->actingAs($student['user'])->get(route('student.announcements.show', $published))->assertNotFound();
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0);
        $published->update(['status' => 'draft']);
        $this->actingAs($admin)->delete(route('admin.announcements.destroy', $published))->assertSessionHasErrors('announcement');
        $this->assertDatabaseHas('announcements', ['id' => $published->id]);
    }

    public function test_archiving_cancels_a_queued_push_without_claiming_delivery_or_erasing_the_event(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $student = $this->createStudentFixture('R2-PUSH');
        $notice = $this->notice($admin);
        $job = $this->queuedAnnouncement($student['user'], $notice);
        $this->actingAs($admin)->patch(route('admin.announcements.archive', $notice))->assertRedirect();
        $fcm = $this->mock(FcmService::class);
        $fcm->shouldNotReceive('sendToUser');
        $job->handle($fcm);
        $event = DB::table('fcm_notification_events')->where('id', $job->deliveryId)->first();
        $this->assertNotNull($event->cancelled_at);
        $this->assertNull($event->sent_at);
        $this->assertSame('announcement_withdrawn', $event->cancellation_reason);
        $this->assertSame(0, app(FcmNotificationService::class)->retryPending());
    }

    public function test_stale_push_job_rechecks_retargeted_and_expired_announcements(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $student = $this->createStudentFixture('R2-STALE');
        $other = $this->createStudentFixture('R2-NEW-TARGET');
        $fcm = $this->mock(FcmService::class);
        $fcm->shouldNotReceive('sendToUser');
        foreach ([['classroom_id' => $other['classroom']->id], ['expires_at' => now()->subSecond()]] as $change) {
            $notice = $this->notice($admin);
            $job = $this->queuedAnnouncement($student['user'], $notice);
            $notice->update($change);
            $job->handle($fcm);
            $this->assertDatabaseHas('fcm_notification_events', ['id' => $job->deliveryId, 'cancellation_reason' => 'announcement_unavailable', 'sent_at' => null]);
        }
    }

    public function test_cancellation_migration_refuses_to_discard_cancelled_delivery_history(): void
    {
        Queue::fake();
        $admin = $this->createAdmin();
        $student = $this->createStudentFixture('R2-MIGRATION');
        $notice = $this->notice($admin);
        $job = $this->queuedAnnouncement($student['user'], $notice);
        $this->actingAs($admin)->patch(route('admin.announcements.archive', $notice));
        $migration = require database_path('migrations/2026_11_11_000001_add_fcm_event_cancellation.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve cancellation history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cancellation history', $exception->getMessage());
        }
        $this->assertNotNull(DB::table('fcm_notification_events')->where('id', $job->deliveryId)->value('cancelled_at'));
    }

    public function test_queue_outage_keeps_one_saved_announcement_and_recoverable_delivery(): void
    {
        $student = $this->createStudentFixture('R2-QUEUE')['user'];
        $token = 'QA-queue-outage-device';
        $student->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        $actualQueue = Queue::connection('database');
        $failedQueue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $failedQueue->shouldReceive('push')->once()->andThrow(new \RuntimeException('QA isolated queue outage'));
        Queue::shouldReceive('connection')->with('database')->andReturn($failedQueue, $actualQueue);
        $this->actingAs($this->createAdmin())->post(route('admin.announcements.store'), [
            'title' => 'QA saved once', 'content' => 'Synthetic', 'audience' => 'students', 'status' => 'published',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('fcm_notification_events', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('fcm:retry-pending')->assertSuccessful();
        $this->artisan('fcm:retry-pending')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_internal_notification_failure_rolls_back_the_announcement_and_audit(): void
    {
        $this->mock(StudentNotificationService::class)->shouldReceive('sendAnnouncement')->once()
            ->andThrow(new \RuntimeException('QA isolated persistence failure'));
        $this->actingAs($this->createAdmin())->post(route('admin.announcements.store'), [
            'title' => 'QA rollback', 'content' => 'Synthetic', 'audience' => 'students', 'status' => 'published',
        ])->assertServerError();
        $this->assertDatabaseCount('announcements', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('module', 'announcements')->count());
    }

    private function notice(User $admin, array $values = []): Announcement
    {
        return Announcement::create(array_replace([
            'title' => 'QA notice', 'content' => 'Fixture only', 'audience' => 'students', 'status' => 'published', 'created_by' => $admin->id,
        ], $values));
    }

    private function queuedAnnouncement(User $user, Announcement $notice): SendFcmNotification
    {
        $token = 'QA-only-device-'.$user->id;
        FcmDevice::firstOrCreate(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)], ['token' => $token]);
        $payload = ['title' => $notice->title, 'body' => 'QA only', 'url' => route('student.announcements.show', $notice, false)];
        app(FcmNotificationService::class)->dispatch($user, 'announcement:'.$notice->id, $payload);
        $event = DB::table('fcm_notification_events')->where('user_id', $user->id)->where('event_key', 'announcement:'.$notice->id)->first();

        return new SendFcmNotification($user->id, $event->event_key, $event->id, $payload);
    }
}
