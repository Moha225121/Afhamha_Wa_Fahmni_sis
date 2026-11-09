<?php

// Standalone synthetic PostgreSQL upgrade/restore probe. Never reads a real .env.
use App\Models\Student;
use App\Models\TutorConversation;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\SmartTutorConversationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

[$script, $markerPath, $phase, $baselinePath, $evidencePath] = $argv;
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$marker = json_decode(ltrim(file_get_contents($markerPath), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
$data = realpath($marker['data']);
$run = realpath($marker['run']);
if ($marker['purpose'] !== 'nasbia-postgres-qa' || ! $run || ! $data
    || ! str_starts_with($run.DIRECTORY_SEPARATOR, realpath($marker['root'].'/tmp/qa-postgres').DIRECTORY_SEPARATOR)
    || $data !== $run.DIRECTORY_SEPARATOR.'data' || ! str_starts_with(realpath($evidencePath).DIRECTORY_SEPARATOR, $run.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid isolated cluster/evidence marker.');
}
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($root.'/tests/Fixtures/no-environment');
$app->loadEnvironmentFrom('.env.disabled-for-tests');
if ($app->configurationIsCached()) throw new RuntimeException('Refusing cached configuration.');
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getConfig();
if (! $app->environment('testing') || $db['driver'] !== 'pgsql' || $db['host'] !== '127.0.0.1'
    || (int) $db['port'] !== (int) $marker['port'] || $db['username'] !== 'qa_release_owner'
    || ! in_array($db['database'], ['nasbia_qa_upgrade', 'nasbia_qa_restore'], true) || ! empty($db['url'])) {
    throw new RuntimeException('Refusing a database outside the dedicated PostgreSQL upgrade probe.');
}
$identity = DB::selectOne("select current_setting('data_directory') as directory, current_database() as database");
if (str_replace('\\', '/', $identity->directory) !== str_replace('\\', '/', $data) || $identity->database !== $db['database']) {
    throw new RuntimeException('The database is not in the marked synthetic cluster.');
}
Http::preventStrayRequests();
config(['queue.connections.database.connection' => 'pgsql', 'services.firebase.credentials' => null,
    'services.firebase.project_id' => null, 'mail.default' => 'array', 'smart_tutor.gemini.api_key' => '']);
$checks = [];
$assert = function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) throw new RuntimeException('Failed check: '.$name);
    $checks[] = $name;
};
$tables = ['users', 'students', 'teachers', 'academic_years', 'classrooms', 'tutor_conversations', 'tutor_messages', 'fcm_devices', 'fcm_notification_events', 'fcm_notification_deliveries', 'jobs', 'notifications'];
$snapshot = function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) {
        $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }
    return $result;
};
$save = fn (string $name, array $value) => file_put_contents($evidencePath.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$read = fn (string $name) => json_decode(file_get_contents($evidencePath.'/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
$expectSqlState = function (string $state, callable $operation, string $name) use ($assert): void {
    try { DB::transaction($operation); throw new RuntimeException('Expected constraint error: '.$name); }
    catch (Illuminate\Database\QueryException $exception) { $assert($exception->getCode() === $state, $name); }
};
$newFiles = ['2026_11_08_000001_add_fcm_outbox_payload.php', '2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php'];
$migrateNew = function () use ($newFiles): void {
    foreach ($newFiles as $file) {
        if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$file, '--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Release migration failed: '.$file);
        }
    }
};
$started = microtime(true);
if ($phase === 'baseline') {
    $assert($db['database'] === 'nasbia_qa_upgrade', 'dedicated upgrade database');
    $assert((int) DB::selectOne("select count(*) as n from information_schema.tables where table_schema = 'public'")->n === 0, 'baseline starts in an exclusively new empty database');
    // Files are exported from the exact baseline Git object or byte-verified
    // against its manifest before temporary source copies are allowed.
    config(['school_accounts' => require $baselinePath.'/config/school_accounts.php', 'smart_tutor' => require $baselinePath.'/config/smart_tutor.php']);
    $assert(Artisan::call('migrate', ['--path' => $baselinePath.'/database/migrations', '--realpath' => true, '--force' => true, '--no-interaction' => true]) === 0, 'baseline migrations from Git reference applied');
    $assert(! Schema::hasColumn('tutor_conversations', 'teacher_user_id') && ! Schema::hasColumn('fcm_notification_events', 'payload'), 'baseline excludes both new migrations');
    $when = '2026-10-01 10:15:00';
    $userIds = [];
    foreach (['student-a' => 'student', 'student-b' => 'student', 'teacher-a' => 'teacher', 'teacher-b' => 'teacher', 'inactive-teacher' => 'teacher'] as $name => $role) {
        $userIds[$name] = DB::table('users')->insertGetId(['name' => 'QA PG '.$name, 'email' => $name.'@example.test', 'password' => password_hash(Str::random(40), PASSWORD_BCRYPT), 'role' => $role, 'status' => $name === 'inactive-teacher' ? 'inactive' : 'active', 'created_at' => $when, 'updated_at' => $when]);
    }
    $year = DB::table('academic_years')->insertGetId(['name' => 'QA PG baseline year', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
    $class = DB::table('classrooms')->insertGetId(['name' => 'QA PG class', 'stage' => 'أساسي', 'section' => 'A', 'academic_year_id' => $year]);
    $studentIds = [];
    foreach (['student-a', 'student-b'] as $name) {
        $studentIds[$name] = DB::table('students')->insertGetId(['user_id' => $userIds[$name], 'student_number' => 'QA-PG-'.$name, 'classroom_id' => $class, 'status' => 'active']);
        $conversation = DB::table('tutor_conversations')->insertGetId(['student_id' => $studentIds[$name], 'title' => 'QA PG legacy '.$name, 'created_at' => $when, 'updated_at' => $when]);
        DB::table('tutor_messages')->insert(['tutor_conversation_id' => $conversation, 'role' => 'user', 'content' => 'QA PG legacy private '.$name, 'delivery_status' => 'failed', 'failure_reason' => 'legacy_unanswered', 'created_at' => $when, 'updated_at' => $when]);
    }
    foreach (['teacher-a', 'teacher-b', 'inactive-teacher'] as $name) DB::table('teachers')->insert(['user_id' => $userIds[$name], 'status' => $name === 'inactive-teacher' ? 'inactive' : 'active']);
    $token = 'QA-PG-synthetic-device-token-only';
    $device = DB::table('fcm_devices')->insertGetId(['user_id' => $userIds['student-a'], 'token' => $token, 'token_hash' => hash('sha256', $token), 'created_at' => $when, 'updated_at' => $when]);
    foreach (['sent', 'pending', 'partial'] as $state) {
        $event = DB::table('fcm_notification_events')->insertGetId(['user_id' => $userIds['student-a'], 'event_key' => 'qa-pg-legacy:'.$state, 'sent_at' => $state === 'sent' ? $when : null, 'created_at' => $when, 'updated_at' => $when]);
        if ($state === 'partial') DB::table('fcm_notification_deliveries')->insert(['event_id' => $event, 'device_id' => $device, 'created_at' => $when, 'updated_at' => $when]);
    }
    $expectSqlState('23503', fn () => DB::table('tutor_conversations')->insert(['student_id' => 99999999, 'title' => 'QA invalid foreign key']), 'legacy student foreign key rejects unknown owner');
    $save('fixtures', ['users' => $userIds, 'students' => $studentIds, 'legacy' => $snapshot(), 'legacy_migrations' => DB::table('migrations')->count()]);
} elseif ($phase === 'upgrade') {
    $fixture = $read('fixtures');
    $assert($snapshot() === $fixture['legacy'], 'pre-upgrade snapshot unchanged since synthetic backup');
    $migrateNew();
    $assert(DB::table('migrations')->count() === $fixture['legacy_migrations'] + 2, 'exactly two release migrations applied');
    $assert(DB::table('tutor_conversations')->whereNotNull('teacher_user_id')->count() === 0, 'legacy student ownership was not reassigned');
    $assert(DB::table('fcm_notification_events')->whereNotNull('payload')->count() === 0 && DB::table('fcm_notification_events')->whereNotNull('queued_at')->count() === 0, 'legacy delivery metadata remains null');
    $assert(app(FcmNotificationService::class)->retryPending() === 0 && DB::table('jobs')->count() === 0, 'legacy sent pending and partial events are not replayed');
    $outbox = require database_path('migrations/'.$newFiles[0]);
    $ownership = require database_path('migrations/'.$newFiles[1]);
    DB::transaction(function () use ($ownership, $outbox) { $ownership->down(); $outbox->down(); });
    $assert($snapshot() === $fixture['legacy'], 'safe down before new data preserves every legacy fixture field');
    DB::transaction(function () use ($outbox, $ownership) { $outbox->up(); $ownership->up(); });
    $key = (string) Str::uuid();
    $teacherA = TutorConversation::create(['teacher_user_id' => $fixture['users']['teacher-a'], 'creation_request_id' => $key, 'title' => 'QA PG teacher A']);
    $teacherB = TutorConversation::create(['teacher_user_id' => $fixture['users']['teacher-b'], 'creation_request_id' => $key, 'title' => 'QA PG teacher B']);
    $expectSqlState('23505', fn () => DB::table('tutor_conversations')->insert(['teacher_user_id' => $fixture['users']['teacher-a'], 'creation_request_id' => $key, 'title' => 'QA duplicate']), 'teacher-scoped creation key is unique in PostgreSQL');
    $expectSqlState('23503', fn () => DB::table('tutor_conversations')->insert(['teacher_user_id' => 99999999, 'title' => 'QA invalid']), 'new teacher foreign key rejects unknown user');
    $expectSqlState('23503', fn () => DB::table('tutor_conversations')->insert(['student_id' => 99999999, 'title' => 'QA invalid']), 'legacy student foreign key survives upgrade');
    $assert($teacherA->id !== $teacherB->id && $teacherA->student_id === null, 'same creation UUID is allowed for independent teacher owners');
    $assert(Student::findOrFail($fixture['students']['student-a'])->tutorConversations()->count() === 1, 'student reads only its old private history');
    $unknown = DB::table('tutor_conversations')->insertGetId(['title' => 'QA unowned imported row']);
    $ambiguous = DB::table('tutor_conversations')->insertGetId(['student_id' => $fixture['students']['student-a'], 'teacher_user_id' => $fixture['users']['teacher-a'], 'title' => 'QA ambiguous imported row']);
    foreach ([$unknown, $ambiguous] as $invalidId) {
        try { app(SmartTutorConversationService::class)->send(TutorConversation::findOrFail($invalidId), 'QA refused', (string) Str::uuid()); throw new RuntimeException('Ambiguous owner accepted'); }
        catch (App\Exceptions\SmartTutorGatewayException $exception) { $assert($exception->reason === 'request_conflict', 'invalid owner '.$invalidId.' fails closed before provider'); }
    }
    $assert(Student::findOrFail($fixture['students']['student-a'])->tutorConversations()->count() === 1, 'ambiguous imported row is hidden from student scope');
    $assert(TutorConversation::where('teacher_user_id', $fixture['users']['teacher-a'])->whereNull('student_id')->count() === 1, 'teacher scope excludes ambiguous and other owners');
    $beforeRefusal = $snapshot();
    try { $ownership->down(); throw new LogicException('Unsafe ownership rollback accepted'); }
    catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'teacher conversations'), 'down refuses teacher or unknown history'); }
    $assert($snapshot() === $beforeRefusal, 'refused rollback changes no fixture data');
    $actualQueue = Queue::connection('database');
    $failedQueue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $failedQueue->shouldReceive('push')->once()->andThrow(new RuntimeException('QA synthetic queue outage'));
    Queue::shouldReceive('connection')->with('database')->andReturn($failedQueue, $actualQueue);
    $notifications = app(FcmNotificationService::class);
    $studentUser = User::findOrFail($fixture['users']['student-a']);
    $payload = ['title' => 'QA PG new event', 'body' => 'Synthetic migration recovery'];
    $notifications->record($studentUser, 'qa-pg-new:pending', $payload);
    $assert($notifications->hasPendingDelivery() && DB::table('jobs')->count() === 0, 'new queue failure retains pending event without job');
    $assert($notifications->retryPending() === 1 && DB::table('jobs')->count() === 1, 'pending new event recovers into database queue once');
    $notifications->record($studentUser, 'qa-pg-new:pending', $payload);
    $assert($notifications->retryPending() === 0 && DB::table('jobs')->count() === 1, 'source replay and retry do not enqueue duplicates');
    $beforeDdlRollback = $snapshot();
    try { DB::transaction(function () use ($outbox) { $outbox->down(); throw new RuntimeException('QA interrupted DDL'); }); }
    catch (RuntimeException $exception) { $assert($exception->getMessage() === 'QA interrupted DDL', 'injected PostgreSQL DDL failure observed'); }
    $assert(Schema::hasColumn('fcm_notification_events', 'payload') && $snapshot() === $beforeDdlRollback, 'PostgreSQL transaction rolls back DDL and retains new event payload');
    $migrateNew();
    $assert(DB::table('migrations')->count() === $fixture['legacy_migrations'] + 2 && $snapshot() === $beforeDdlRollback, 're-running Artisan migration is a no-op');
    $save('upgraded-snapshot', $snapshot());
    Mockery::close();
} elseif ($phase === 'restore') {
    $assert($db['database'] === 'nasbia_qa_restore', 'restore uses a separate dedicated database');
    $assert($snapshot() === $read('upgraded-snapshot'), 'pg_restore preserves all checked old and new fixture rows exactly');
    $max = DB::table('tutor_conversations')->max('id');
    $fixture = $read('fixtures');
    DB::beginTransaction();
    try {
        $next = DB::table('tutor_conversations')->insertGetId(['student_id' => $fixture['students']['student-a'], 'title' => 'QA sequence probe']);
        $assert($next > $max, 'restored conversation sequence allocates an unused ID');
    } finally { DB::rollBack(); }
    $assert(DB::table('fcm_notification_events')->where('event_key', 'qa-pg-new:pending')->whereNotNull('payload')->whereNotNull('queued_at')->exists(), 'restored outbox retains durable payload and queue marker');
} else {
    throw new RuntimeException('Unknown probe phase.');
}
Http::assertNothingSent();
$result = ['phase' => $phase, 'result' => 'PASS', 'engine' => DB::selectOne('select version() as version')->version, 'database' => $db['database'], 'checks' => $checks, 'duration_seconds' => round(microtime(true) - $started, 3)];
$save($phase.'-result', $result);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
