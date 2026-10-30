<?php

namespace Tests\Feature;

use App\Jobs\SendTeacherNotification;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherMessageInboxPerformanceTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $actor;

    private array $recipientIds = [];

    private int $classroomId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'queue.connections.database.connection' => config('database.default'),
            'queue.batching.database' => config('database.default'),
        ]);
        // The real database queue remains idle throughout these HTTP requests.
        $this->mock(PushNotificationService::class)->shouldNotReceive('send');
        $this->actor = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $this->actor->id, 'status' => 'active']);
        $fixture = $this->createStudentFixture('INBOX-PERFORMANCE');
        $subject = $this->createSubjectFor($fixture['classroom'], 'INBOX-PERFORMANCE');
        $teacher->classrooms()->attach($fixture['classroom']->id, ['subject_id' => $subject->id]);
        $this->classroomId = (int) $fixture['classroom']->id;
        $this->recipientIds[] = $fixture['user']->id;
        $this->addRecipientsUntil(100);
    }

    private function addRecipientsUntil(int $count): void
    {
        for ($index = count($this->recipientIds); $index < $count; $index++) {
            $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
            Student::create([
                'user_id' => $user->id, 'student_number' => 'INBOX-PERFORMANCE-'.$index,
                'classroom_id' => $this->classroomId, 'status' => 'active',
            ]);
            $this->recipientIds[] = $user->id;
        }
    }

    private function preview(array $recipientIds): string
    {
        return $this->actingAs($this->actor)->post('/teacher/notifications/preview', [
            'audience' => 'families', 'selection_mode' => 'people',
            'student_ids' => $recipientIds, 'title' => 'رسالة اختبار معزول',
            'message' => 'تحفظ هذه الرسالة فوراً في حساب كل مستلم.',
        ])->assertOk()->viewData('previewId');
    }

    /** Count only the confirmed send; exclude fixture creation, preview and assertions. */
    private function confirmedSendQueries(string $dispatch): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $response = $this->post('/teacher/notifications', ['preview_id' => $dispatch, 'confirmed' => 1]);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
        $response->assertSessionHasNoErrors()->assertRedirect(route('teacher.notifications.show', $dispatch));

        return array_column($queries, 'query');
    }

    private function insertsInto(array $queries, string $table): array
    {
        return array_values(array_filter($queries, fn (string $sql): bool => (bool) preg_match(
            '/^\s*insert(?:\s+or\s+ignore)?\s+into\s+["`]?'.preg_quote($table, '/').'["`]?\s/i', $sql,
        )));
    }

    public function test_confirming_a_larger_audience_adds_chunk_queries_without_per_recipient_database_work_or_push(): void
    {
        $smallDispatch = $this->preview(array_slice($this->recipientIds, 0, 2));
        $smallQueries = $this->confirmedSendQueries($smallDispatch);
        $largeDispatch = $this->preview($this->recipientIds);
        $largeQueries = $this->confirmedSendQueries($largeDispatch);

        // Extra recipients change bindings and chunk inserts, never add one SQL round trip per person.
        $this->assertLessThanOrEqual(count($smallQueries) + 6, count($largeQueries),
            'Confirmed-send SQL grew with recipients instead of bounded insert chunks: small='
            .count($smallQueries).', large='.count($largeQueries));
        $this->assertCount(1, $this->insertsInto($smallQueries, 'notifications'));
        $this->assertCount(2, $this->insertsInto($largeQueries, 'notifications'));
        $this->assertLessThanOrEqual(3, count($this->insertsInto($largeQueries, 'audit_logs')));
        $this->assertDatabaseCount('notifications', 102);
        $this->assertDatabaseCount('teacher_message_receipts', 102);
        $this->assertDatabaseCount('jobs', 102);
        $this->assertDatabaseCount('job_batches', 2);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(102, AuditLog::where('module', 'teacher_notification_delivery')->where('action', 'stored')->count());

        $expectedIds = array_map(fn (int $id): string => SendTeacherNotification::notificationId($largeDispatch, $id), $this->recipientIds);
        $rows = DB::table('notifications')->whereIn('id', $expectedIds)->get();
        $this->assertCount(100, $rows);
        foreach ($rows as $row) {
            $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('تحفظ هذه الرسالة فوراً في حساب كل مستلم.', $data['body']);
            $this->assertTrue($data['_message_push_pending']);
        }
    }

    public function test_confirmed_dispatch_survives_lost_submission_cache_without_duplicate_inboxes_audits_or_jobs(): void
    {
        $dispatch = $this->preview(array_slice($this->recipientIds, 0, 2));
        $this->confirmedSendQueries($dispatch);
        $before = [];
        foreach (['notifications', 'teacher_message_receipts', 'audit_logs', 'jobs', 'job_batches'] as $table) {
            $key = $table === 'teacher_message_receipts' ? 'notification_id' : 'id';
            $before[$table] = DB::table($table)->orderBy($key)->get()->toJson();
        }
        Cache::forget('teacher-notification-submitted:'.$dispatch);

        $queries = $this->confirmedSendQueries($dispatch);

        foreach ($before as $table => $rows) {
            $key = $table === 'teacher_message_receipts' ? 'notification_id' : 'id';
            $this->assertSame($rows, DB::table($table)->orderBy($key)->get()->toJson(), 'Replay changed '.$table);
            $this->assertCount(0, $this->insertsInto($queries, $table));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_maximum_selected_audience_is_saved_and_queued_within_128_mib_without_a_worker(): void
    {
        // A separate process prevents previous tests' retained heap from changing this budget.
        ini_set('memory_limit', '128M');
        $this->addRecipientsUntil(2000);
        $dispatch = $this->preview($this->recipientIds);
        $queryCount = 0;
        $measuring = false;
        // Count events without retaining encrypted payload bindings in a query log.
        DB::listen(function () use (&$queryCount, &$measuring): void {
            if ($measuring) {
                $queryCount++;
            }
        });
        gc_collect_cycles();
        memory_reset_peak_usage();
        $measuring = true;
        $response = $this->post('/teacher/notifications', ['preview_id' => $dispatch, 'confirmed' => 1]);
        $measuring = false;
        $sendPeak = memory_get_peak_usage(true);

        $response->assertSessionHasNoErrors()->assertRedirect(route('teacher.notifications.show', $dispatch));
        $this->assertLessThanOrEqual(240, $queryCount, 'Maximum audience must use bounded insert chunks.');
        $this->assertLessThanOrEqual(128 * 1024 * 1024, $sendPeak);
        $this->assertDatabaseCount('notifications', 2000);
        $this->assertDatabaseCount('teacher_message_receipts', 2000);
        $this->assertDatabaseCount('jobs', 2000);
        $this->assertDatabaseCount('job_batches', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(2000, AuditLog::where('module', 'teacher_notification_delivery')->where('action', 'stored')->count());
        $this->assertSame(2000, DB::table('notifications')->where('data->_message_push_pending', true)
            ->where('data->body', 'تحفظ هذه الرسالة فوراً في حساب كل مستلم.')->count());
    }
}
