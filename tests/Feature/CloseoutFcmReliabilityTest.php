<?php

namespace Tests\Feature;

use App\Contracts\FcmAccessTokenProvider;
use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class CloseoutFcmReliabilityTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.firebase.project_id' => 'qa-synthetic-project']);
        $this->app->instance(FcmAccessTokenProvider::class, new class implements FcmAccessTokenProvider {
            public function getAccessToken(): ?string { return 'synthetic-access-token'; }
        });
    }

    private function subscriber(string $suffix, int $devices = 1): User
    {
        $user = $this->createStudentFixture($suffix)['user'];
        foreach (range(1, $devices) as $number) {
            $token = 'QA-'.$suffix.'-'.$number.'-'.Str::random(50);
            $user->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        }
        return $user;
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    }

    private function due(): void
    {
        DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
    }

    public function test_notification_and_recoverable_outbox_exist_in_same_source_transaction_before_any_callback(): void
    {
        $user = $this->subscriber('DURABLE-COMMIT');
        DB::transaction(function () use ($user): void {
            app(FcmNotificationService::class)->record($user, 'qa:durable-commit', ['title' => 'QA', 'body' => 'Synthetic']);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertDatabaseCount('fcm_notification_events', 1);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertNull(DB::table('fcm_notification_events')->value('queued_at'));
        });
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_real_database_worker_retries_only_failed_device_after_partial_acceptance(): void
    {
        $user = $this->subscriber('PARTIAL', 2);
        Http::fakeSequence('https://fcm.googleapis.com/*')->push(['error' => ['status' => 'UNAVAILABLE']], 503)
            ->push(['name' => 'accepted-second'], 200)->push(['name' => 'accepted-first'], 200);
        app(FcmNotificationService::class)->record($user, 'qa:partial-worker', ['title' => 'QA', 'body' => 'Synthetic']);
        $event = DB::table('fcm_notification_events')->first();
        $this->work();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('fcm_notification_deliveries', 1);
        $this->assertNull(DB::table('fcm_notification_events')->value('sent_at'));
        $this->assertSame(1, (int) DB::table('jobs')->value('attempts'));
        $this->due(); $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('fcm_notification_deliveries', 2);
        $this->assertNotNull(DB::table('fcm_notification_events')->value('sent_at'));
        Http::assertSentCount(3);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]['message']['token']);
        $this->assertSame($requests[0], $requests[2]);
        $this->assertNotSame($requests[0], $requests[1]);
        Http::assertSent(fn ($request) => $request['message']['data']['recipient_user_id'] === (string) $user->id
            && $request['message']['data']['event_id'] === (string) $event->id);
        app(FcmNotificationService::class)->record($user, 'qa:partial-worker', ['title' => 'QA', 'body' => 'Synthetic']);
        $this->work();
        Http::assertSentCount(3);
    }

    public function test_unknown_acceptance_timeout_can_duplicate_at_provider_but_never_claims_exactly_once(): void
    {
        $user = $this->subscriber('UNKNOWN');
        $accepted = 0;
        Http::fake(['https://fcm.googleapis.com/*' => function () use (&$accepted) {
            $accepted++;
            if ($accepted === 1) throw new ConnectionException('Synthetic response lost after provider acceptance');
            return Http::response(['name' => 'accepted-again'], 200);
        }]);
        app(FcmNotificationService::class)->record($user, 'qa:unknown', ['title' => 'QA', 'body' => 'Synthetic']);
        $this->work();
        $this->assertSame(1, $accepted);
        $this->assertDatabaseCount('fcm_notification_deliveries', 0);
        $this->assertNull(DB::table('fcm_notification_events')->value('sent_at'));
        $this->due(); $this->work();
        $this->assertSame(2, $accepted);
        $this->assertDatabaseCount('fcm_notification_deliveries', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_permanent_failure_exhausts_four_attempts_and_only_selected_failed_job_is_retried(): void
    {
        $user = $this->subscriber('PERMANENT');
        $recovered = false;
        Http::fake(['https://fcm.googleapis.com/*' => function () use (&$recovered) {
            return $recovered ? Http::response(['name' => 'recovered'], 200)
                : Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400);
        }]);
        app(FcmNotificationService::class)->record($user, 'qa:permanent', ['title' => 'QA', 'body' => 'Synthetic']);
        foreach ([10, 60, 180] as $attempt => $delay) {
            $before = now()->timestamp;
            $this->work();
            $this->assertSame($attempt + 1, (int) DB::table('jobs')->value('attempts'));
            $this->assertGreaterThanOrEqual($before + $delay, (int) DB::table('jobs')->value('available_at'));
            $this->due();
        }
        $this->work();
        Http::assertSentCount(4);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('fcm_notification_deliveries', 0);
        $this->assertNull(DB::table('fcm_notification_events')->value('sent_at'));
        $this->assertNotNull(DB::table('fcm_notification_events')->value('queued_at'));
        $this->assertSame(0, app(FcmNotificationService::class)->retryPending());
        $recovered = true;
        $this->artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('fcm_notification_deliveries', 1);
    }

    public function test_worker_overlap_lock_releases_second_attempt_without_sending(): void
    {
        $user = $this->subscriber('OVERLAP');
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'accepted'], 200)]);
        $payload = ['title' => 'QA', 'body' => 'Synthetic'];
        app(FcmNotificationService::class)->record($user, 'qa:overlap', $payload);
        $event = DB::table('fcm_notification_events')->first();
        $job = new SendFcmNotification($user->id, $event->event_key, $event->id, $payload);
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 120);
        $this->assertTrue($lock->get());
        try {
            $this->work();
            Http::assertNothingSent();
            $this->assertDatabaseCount('jobs', 1);
        } finally { $lock->release(); }
        $this->due(); $this->work();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function overlapStates(): array
    {
        return ['fresh overlapping job' => [false], 'process died after reservation' => [true]];
    }

    #[DataProvider('overlapStates')]
    public function test_overlap_releases_do_not_exhaust_attempts_before_the_existing_lock_expires(bool $crashed): void
    {
        $start = now()->startOfSecond();
        $this->travelTo($start);
        $user = $this->subscriber('LOCK-TTL-'.(int) $crashed);
        Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'accepted'], 200)]);
        $payload = ['title' => 'QA', 'body' => 'Synthetic'];
        app(FcmNotificationService::class)->record($user, 'qa:lock-ttl', $payload);
        $event = DB::table('fcm_notification_events')->first();
        $job = new SendFcmNotification($user->id, $event->event_key, $event->id, $payload);
        $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 120);
        $this->assertTrue($lock->get());
        if ($crashed) {
            DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => $start->timestamp]);
            $this->travelTo($start->copy()->addSeconds(91));
        }
        for ($turn = 0; $turn < 5 && DB::table('jobs')->exists(); $turn++) {
            $pending = DB::table('jobs')->first();
            if ($pending->available_at > now()->timestamp) $this->travelTo(now()->setTimestamp($pending->available_at));
            $this->work();
        }
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('fcm_notification_deliveries', 1);
        Http::assertSentCount(1);
    }

    public function test_token_rotation_account_switch_and_old_session_logout_do_not_delete_new_owner(): void
    {
        $first = $this->subscriber('ROTATION');
        $second = $this->subscriber('SWITCH');
        $old = 'QA-old-'.Str::random(50); $rotated = 'QA-rotated-'.Str::random(50);
        $this->actingAs($first)->postJson(route('fcm.devices.store'), ['token' => $old])->assertOk();
        $this->postJson(route('fcm.devices.store'), ['token' => $rotated])->assertOk();
        $this->assertDatabaseMissing('fcm_devices', ['token_hash' => hash('sha256', $old)]);
        $this->actingAs($second)->postJson(route('fcm.devices.store'), ['token' => $rotated])->assertOk();
        $this->getJson(route('fcm.devices.status'))->assertOk()->assertJsonPath('user_id', (string) $second->id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($first)->post(route('logout'))->assertRedirect();
        $this->assertDatabaseHas('fcm_devices', ['user_id' => $second->id, 'token_hash' => hash('sha256', $rotated)]);
        $this->getJson(route('fcm.devices.status'))->assertUnauthorized();
    }

    public function test_token_reassigned_between_device_snapshot_and_send_is_not_sent_old_accounts_payload(): void
    {
        $user = $this->subscriber('STALE', 2);
        $other = $this->subscriber('NEW-OWNER');
        $devices = $user->fcmDevices()->orderBy('id')->get();
        Http::fake(['https://fcm.googleapis.com/*' => function () use ($devices, $other) {
            DB::table('fcm_devices')->where('id', $devices[1]->id)->update(['user_id' => $other->id]);
            return Http::response(['name' => 'accepted'], 200);
        }]);
        app(FcmService::class)->sendToUser($user, ['title' => 'QA', 'body' => 'Synthetic']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('fcm_devices', ['id' => $devices[1]->id, 'user_id' => $other->id]);
    }
}
