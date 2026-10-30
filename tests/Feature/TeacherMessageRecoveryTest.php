<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherNotification;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Services\TeacherMessageReceiptService;
use App\Services\TeacherMessageRecoveryService;
use App\Services\TeacherNotificationAudience;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherMessageRecoveryTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $actor;

    private Teacher $teacher;

    private array $student;

    private User $parent;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['queue.connections.database.connection' => config('database.default'), 'queue.batching.database' => config('database.default')]);
        $this->actor = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        $this->student = $this->createStudentFixture('MESSAGE-RECOVERY');
        $subject = $this->createSubjectFor($this->student['classroom'], 'MESSAGE-RECOVERY');
        $this->teacher->classrooms()->attach($this->student['classroom']->id, ['subject_id' => $subject->id]);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $this->guardian->students()->attach($this->student['student']->id);
    }

    /** Match the pre-fix transaction: encrypted batch plus receipts, with no notification inbox rows. */
    private function legacyDispatch(): string
    {
        $dispatchId = (string) Str::uuid();
        $filters = ['audience' => 'families', 'selection_mode' => 'people', 'student_ids' => [$this->student['user']->id]];
        $audience = app(TeacherNotificationAudience::class);
        $recipients = $audience->resolve($this->teacher, $filters);
        $contexts = $audience->contexts($this->teacher, $filters, $recipients);
        DB::transaction(function () use ($dispatchId, $filters, $recipients, $contexts): void {
            $jobs = $recipients->map(function ($recipient) use ($dispatchId, $filters, $contexts) {
                app(TeacherMessageReceiptService::class)->prepare(SendTeacherNotification::notificationId($dispatchId, $recipient->id), $this->actor->id, $recipient->id, $dispatchId);

                return new SendTeacherNotification($this->actor->id, $dispatchId, $recipient->id, $filters, 'عنوان قديم', 'رسالة عربية قديمة معلقة.', $contexts[$recipient->id]);
            });
            $batch = Bus::batch($jobs)->name('teacher-notification:'.$dispatchId)->onConnection('database')->onQueue('notifications')->allowFailures()->dispatch();
            AuditLog::create(['user_id' => $this->actor->id, 'module' => 'teacher_notifications', 'action' => 'queued', 'record_id' => $this->teacher->id,
                'new_values' => ['dispatch_id' => $dispatchId, 'batch_id' => $batch->id, 'recipient_count' => $recipients->count(), 'title' => 'عنوان قديم']]);
        });

        return $dispatchId;
    }

    private function recover(string $dispatchId, array $input = [])
    {
        return $this->actingAs($this->actor)->post(route('teacher.notifications.recover', $dispatchId), $input);
    }

    private function replaceQueuedObject(int $id, callable $replace): void
    {
        $envelope = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($envelope['data']['command']));
        $envelope['data']['command'] = Crypt::encrypt(serialize($replace($job)));
        DB::table('jobs')->where('id', $id)->update(['payload' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
    }

    public function test_old_encrypted_messages_restore_without_consuming_jobs_or_sending_push_and_worker_later_pushes_once(): void
    {
        $dispatch = $this->legacyDispatch();
        $jobsBefore = DB::table('jobs')->orderBy('id')->get()->toJson();
        $batchesBefore = DB::table('job_batches')->get()->toJson();
        $pushes = [];
        $this->mock(PushNotificationService::class)->shouldReceive('send')->twice()->andReturnUsing(function ($user, $payload) use (&$pushes) {
            $pushes[] = $user->id;

            return ['fcm_accepted' => 1, 'web_push_accepted' => 0];
        });
        $this->recover($dispatch)->assertRedirect(route('teacher.notifications.show', $dispatch))->assertSessionHas('message_recovery.restored', 2);
        $this->assertSame([], $pushes);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame($jobsBefore, DB::table('jobs')->orderBy('id')->get()->toJson());
        $this->assertSame($batchesBefore, DB::table('job_batches')->get()->toJson());
        $this->assertSame('رسالة عربية قديمة معلقة.', $this->student['user']->notifications()->sole()->data['body']);
        $this->assertTrue($this->parent->notifications()->sole()->data['_message_push_pending']);
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()->assertViewHas('stored', 2);
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
        $this->assertDatabaseCount('notifications', 2);
        while ($job = Queue::connection('database')->pop('notifications')) {
            $job->fire();
        }
        $this->assertEqualsCanonicalizing([$this->student['user']->id, $this->parent->id], $pushes);
        $this->assertArrayNotHasKey('_message_push_pending', $this->parent->notifications()->sole()->data);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
    }

    public function test_recovery_requires_author_ownership_active_teacher_and_uncancelled_exact_batch(): void
    {
        $dispatch = $this->legacyDispatch();
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        $url = route('teacher.notifications.recover', $dispatch);
        $this->actingAs($other)->post($url)->assertNotFound();
        $this->actingAs($this->parent)->post($url)->assertForbidden();
        $this->teacher->update(['status' => 'inactive']);
        $this->recover($dispatch)->assertForbidden();
        $this->teacher->update(['status' => 'active']);
        $this->actor->unsetRelation('teacher');
        DB::table('job_batches')->update(['cancelled_at' => now()->timestamp]);
        $this->recover($dispatch)->assertNotFound();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_recovery_revalidates_student_status_and_teacher_assignment_before_storing_old_messages(): void
    {
        $dispatch = $this->legacyDispatch();
        $this->student['user']->update(['status' => 'inactive']);
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->student['user']->update(['status' => 'active']);
        $this->teacher->classrooms()->detach();
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_removed_guardian_link_prevents_recovery_to_that_parent_without_blocking_the_student(): void
    {
        $dispatch = $this->legacyDispatch();
        $this->guardian->students()->detach();
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 1);
        $this->assertSame(1, $this->student['user']->notifications()->count());
        $this->assertSame(0, $this->parent->notifications()->count());
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_mismatched_encrypted_dispatch_or_batch_and_missing_receipt_cannot_restore_messages(): void
    {
        $dispatch = $this->legacyDispatch();
        $ids = DB::table('jobs')->orderBy('id')->pluck('id');
        $this->replaceQueuedObject($ids[0], function ($job) {
            $job->batchId = (string) Str::uuid();

            return $job;
        });
        $this->replaceQueuedObject($ids[1], function ($job) {
            $job->dispatchId = (string) Str::uuid();

            return $job;
        });
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);

        $second = $this->legacyDispatch();
        TeacherMessageReceipt::where('dispatch_id', $second)->delete();
        $this->recover($second)->assertSessionHas('message_recovery.restored', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_corrupt_encrypted_data_and_other_serialized_classes_are_ignored_without_invoking_them(): void
    {
        $dispatch = $this->legacyDispatch();
        $ids = DB::table('jobs')->orderBy('id')->pluck('id');
        $this->replaceQueuedObject($ids[0], fn () => new RecoveryWakeupSentinel);
        $row = json_decode(DB::table('jobs')->where('id', $ids[1])->value('payload'), true);
        $row['data']['command'] = 'PRIVATE-CORRUPT-CIPHERTEXT';
        DB::table('jobs')->where('id', $ids[1])->update(['payload' => json_encode($row)]);
        RecoveryWakeupSentinel::$invoked = false;
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0)->assertDontSee('PRIVATE-CORRUPT-CIPHERTEXT');
        $this->assertFalse(RecoveryWakeupSentinel::$invoked);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_backlog_scanning_is_bounded_and_continues_from_the_returned_cursor(): void
    {
        $rows = array_fill(0, TeacherMessageRecoveryService::PAGE_SIZE + 1, ['queue' => 'notifications', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('jobs')->insert($rows);
        $cursor = (int) DB::table('jobs')->orderBy('id')->skip(TeacherMessageRecoveryService::PAGE_SIZE - 1)->value('id');
        $dispatch = $this->legacyDispatch();
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0)->assertSessionHas('message_recovery.next', $cursor);
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()->assertSee('متابعة استكمال الإرسال');
        $this->assertDatabaseCount('notifications', 0);
        $this->recover($dispatch, ['after' => $cursor])->assertSessionHas('message_recovery.restored', 2)
            ->assertSessionHas('message_recovery', fn ($result) => array_key_exists('next', $result) && $result['next'] === null);
        $this->assertDatabaseCount('jobs', 253);
    }

    public function test_authenticated_outer_objects_never_run_unserialize_or_wakeup_hooks(): void
    {
        $dispatch = $this->legacyDispatch();
        $rows = DB::table('jobs')->orderBy('id')->get();
        foreach ([new RecoveryWakeupSentinel, new RecoveryUnserializeSentinel] as $index => $unexpectedObject) {
            $envelope = json_decode($rows[$index]->payload, true, 512, JSON_THROW_ON_ERROR);
            // Authenticated ciphertext, but the encrypted outer value must be a string.
            $envelope['data']['command'] = Crypt::encrypt($unexpectedObject);
            DB::table('jobs')->where('id', $rows[$index]->id)->update(['payload' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
        }
        RecoveryWakeupSentinel::$invoked = false;
        RecoveryUnserializeSentinel::$invoked = false;
        $this->recover($dispatch)->assertSessionHas('message_recovery.restored', 0);
        $this->assertFalse(RecoveryWakeupSentinel::$invoked);
        $this->assertFalse(RecoveryUnserializeSentinel::$invoked);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);
        $this->get(route('teacher.notifications.show', $dispatch))->assertOk()
            ->assertSee('لم يُعثر على طلب إرسال قابل للاستكمال')->assertDontSee('تم إرسال 0');
    }

    public function test_recovery_rejects_client_payloads_bad_cursors_and_cross_database_queue_configuration(): void
    {
        $dispatch = $this->legacyDispatch();
        foreach ([['after' => -1], ['after' => 'invalid'], ['payload' => 'forged'], ['job_id' => 1]] as $input) {
            $this->actingAs($this->actor)->postJson(route('teacher.notifications.recover', $dispatch), $input)->assertUnprocessable();
        }
        config(['queue.connections.database.connection' => 'unverified-database']);
        $this->actingAs($this->actor)->postJson(route('teacher.notifications.recover', $dispatch))->assertUnprocessable()->assertJsonValidationErrors('recovery');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_recovery_is_post_only_and_requires_csrf(): void
    {
        $dispatch = $this->legacyDispatch();
        $url = route('teacher.notifications.recover', $dispatch);
        $this->actingAs($this->actor)->get($url)->assertStatus(405);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($url)->assertStatus(419);
        $this->assertDatabaseCount('notifications', 0);
    }
}

class RecoveryWakeupSentinel
{
    public static bool $invoked = false;

    public function __wakeup(): void
    {
        self::$invoked = true;
    }
}

class RecoveryUnserializeSentinel
{
    public static bool $invoked = false;

    public function __unserialize(array $data): void
    {
        self::$invoked = true;
    }
}
