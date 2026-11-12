<?php

// Dedicated synthetic Round 2 upgrade/restore probe; never bootstrap a real .env.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

[$script, $markerPath, $phase, $baselinePath, $evidencePath] = $argv;
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$marker = json_decode(ltrim(file_get_contents($markerPath), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
$run = realpath($marker['run'] ?? '');
$data = realpath($marker['data'] ?? '');
$base = realpath($root.'/tmp/qa-postgres');
if (($marker['purpose'] ?? '') !== 'nasbia-postgres-qa' || realpath($marker['root'] ?? '') !== realpath($root)
    || ! $base || ! $run || ! $data || ! str_starts_with($run.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR)
    || $data !== $run.DIRECTORY_SEPARATOR.'data' || ! realpath($evidencePath)
    || ! str_starts_with(realpath($evidencePath).DIRECTORY_SEPARATOR, $run.DIRECTORY_SEPARATOR)
    || ! realpath($baselinePath) || ! str_starts_with(realpath($baselinePath).DIRECTORY_SEPARATOR, realpath($evidencePath).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Invalid isolated cluster, source or evidence marker.');
}
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($root.'/tests/Fixtures/no-environment');
$app->loadEnvironmentFrom('.env.disabled-for-tests');
if ($app->configurationIsCached()) throw new RuntimeException('Refusing cached configuration.');
$app->make(Kernel::class)->bootstrap();
$db = DB::connection()->getConfig();
$expected = $phase === 'restore' ? 'nasbia_qa_restore' : 'nasbia_qa_upgrade';
if (! in_array($phase, ['baseline', 'upgrade', 'restore'], true) || ! $app->environment('testing')
    || $db['driver'] !== 'pgsql' || $db['host'] !== '127.0.0.1' || (int) $db['port'] !== (int) $marker['port']
    || $db['username'] !== 'qa_release_owner' || $db['database'] !== $expected || ! empty($db['url'])) {
    throw new RuntimeException('Refusing database outside the dedicated Round 2 probe.');
}
$identity = DB::selectOne("select current_setting('data_directory') as directory, current_database() as database");
if (str_replace('\\', '/', $identity->directory) !== str_replace('\\', '/', $data) || $identity->database !== $expected) {
    throw new RuntimeException('Database identity does not match the isolated cluster.');
}
Http::preventStrayRequests();
config(['services.firebase.credentials' => null, 'services.firebase.project_id' => null,
    'services.web_push.public_key' => null, 'services.web_push.private_key' => null, 'smart_tutor.gemini.api_key' => '', 'mail.default' => 'array']);
$checks = [];
$assert = function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) throw new RuntimeException('Failed check: '.$name);
    $checks[] = $name;
};
$save = fn (string $name, array $value) => file_put_contents($evidencePath.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$read = fn (string $name) => json_decode(file_get_contents($evidencePath.'/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
$columns = function (): array {
    $result = [];
    foreach (DB::select("select table_name from information_schema.tables where table_schema='public' order by table_name") as $table) {
        $result[$table->table_name] = array_column(Schema::getColumns($table->table_name), 'name');
    }
    return $result;
};
$snapshot = function (?array $schema = null) use ($columns): array {
    $result = [];
    foreach ($schema ?? $columns() as $table => $fields) {
        $rows = DB::table($table)->select($fields)->get()->map(function ($row): string {
            $values = (array) $row;
            ksort($values);
            return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        })->all();
        sort($rows, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
    return $result;
};
$newFiles = ['2026_11_11_000001_add_fcm_event_cancellation.php', '2026_11_11_000002_create_lesson_save_requests.php'];
$migrate = function () use ($newFiles): void {
    foreach ($newFiles as $file) {
        if (Artisan::call('migrate', ['--path' => 'database/migrations/'.$file, '--force' => true, '--no-interaction' => true]) !== 0) throw new RuntimeException('Release migration failed: '.$file);
    }
};
$expectConstraint = function (string $code, callable $fn, string $name) use ($assert): void {
    try { DB::transaction($fn); throw new LogicException('Expected a constraint rejection.'); }
    catch (Illuminate\Database\QueryException $exception) { $assert($exception->getCode() === $code, $name); }
};
$started = microtime(true);
if ($phase === 'baseline') {
    $assert(count($columns()) === 0, 'baseline starts in an empty isolated database');
    DB::transaction(function () use ($baselinePath, $assert, $save, $snapshot, $columns): void {
        config(['school_accounts' => require $baselinePath.'/config/school_accounts.php', 'smart_tutor' => require $baselinePath.'/config/smart_tutor.php']);
        $assert(Artisan::call('migrate', ['--path' => $baselinePath.'/database/migrations', '--realpath' => true, '--force' => true, '--no-interaction' => true]) === 0, 'exact baseline migration files applied');
        $assert(DB::table('migrations')->count() === 44, '44 baseline migrations recorded');
        $assert(! Schema::hasColumn('fcm_notification_events', 'cancelled_at') && ! Schema::hasTable('lesson_save_requests'), 'both Round 2 additions absent from baseline');
        $when = '2026-11-01 10:15:00';
        $users = [];
        foreach (['admin', 'teacher', 'student', 'parent'] as $role) {
            $users[$role] = DB::table('users')->insertGetId(['name' => 'QA R2 '.$role, 'email' => 'r2-'.$role.'@example.test', 'password' => password_hash(Str::random(40), PASSWORD_BCRYPT), 'role' => $role, 'status' => 'active', 'created_at' => $when, 'updated_at' => $when]);
        }
        $year = DB::table('academic_years')->insertGetId(['name' => 'QA R2 year', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $class = DB::table('classrooms')->insertGetId(['name' => 'QA R2 class', 'stage' => 'أساسي', 'section' => 'A', 'academic_year_id' => $year]);
        $subject = DB::table('subjects')->insertGetId(['name' => 'QA R2 subject', 'code' => 'QA-R2', 'stage' => 'أساسي', 'status' => 'active']);
        $teacher = DB::table('teachers')->insertGetId(['user_id' => $users['teacher'], 'status' => 'active']);
        $student = DB::table('students')->insertGetId(['user_id' => $users['student'], 'student_number' => 'QA-R2-STUDENT', 'classroom_id' => $class, 'status' => 'active']);
        $guardian = DB::table('guardians')->insertGetId(['user_id' => $users['parent'], 'relationship' => 'parent', 'status' => 'active']);
        DB::table('guardian_student')->insert(['guardian_id' => $guardian, 'student_id' => $student]);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher, 'classroom_id' => $class, 'subject_id' => $subject]);
        $lesson = DB::table('lessons')->insertGetId(['subject_id' => $subject, 'classroom_id' => $class, 'teacher_id' => $teacher, 'title' => 'QA R2 lesson', 'content' => 'Synthetic preserved content', 'status' => 'published', 'published_at' => $when, 'submission_key' => (string) Str::uuid()]);
        $file = 'qa-round-two/synthetic-attachment.txt';
        Storage::disk('local')->put($file, 'Synthetic protected attachment, no school data.');
        DB::table('lesson_attachments')->insert(['lesson_id' => $lesson, 'title' => 'QA attachment', 'disk' => 'local', 'file_path' => $file, 'original_name' => 'synthetic.txt', 'mime_type' => 'text/plain', 'size' => Storage::disk('local')->size($file)]);
        $exam = DB::table('exams')->insertGetId(['title' => 'QA R2 exam', 'subject_id' => $subject, 'classroom_id' => $class, 'teacher_id' => $teacher, 'starts_at' => $when, 'duration_minutes' => 30, 'total_score' => 10, 'status' => 'published']);
        $question = DB::table('exam_questions')->insertGetId(['exam_id' => $exam, 'question_text' => 'QA question', 'type' => 'short_answer', 'score' => 10]);
        $attempt = DB::table('exam_attempts')->insertGetId(['exam_id' => $exam, 'student_id' => $student, 'started_at' => $when, 'expires_at' => '2026-11-01 10:45:00', 'submitted_at' => $when, 'graded_at' => $when, 'status' => 'submitted', 'score' => 8, 'maximum_score' => 10, 'percentage' => 80]);
        DB::table('exam_answers')->insert(['exam_attempt_id' => $attempt, 'exam_question_id' => $question, 'question_text_snapshot' => 'QA question', 'question_type_snapshot' => 'short_answer', 'max_score' => 10, 'answer' => 'Synthetic saved answer', 'awarded_score' => 8]);
        DB::table('grades')->insert(['exam_id' => $exam, 'student_id' => $student, 'score' => 8]);
        $period = DB::table('exam_periods')->insertGetId(['name' => 'QA R2 period', 'academic_year_id' => $year, 'term' => 'الأول', 'exam_type' => 'شهري', 'status' => 'published', 'published_at' => $when, 'revision' => 1, 'created_by' => $users['admin']]);
        DB::table('result_marks')->insert(['exam_period_id' => $period, 'student_id' => $student, 'subject_id' => $subject, 'score' => 8, 'maximum_score' => 10]);
        DB::table('result_publications')->insert(['exam_period_id' => $period, 'student_id' => $student, 'payload' => '{"total":8,"maximum":10}']);
        $conversation = DB::table('conversations')->insertGetId(['student_id' => $student, 'created_by' => $users['parent'], 'subject' => 'QA history']);
        foreach ([$users['parent'], $users['admin']] as $user) DB::table('conversation_participants')->insert(['conversation_id' => $conversation, 'user_id' => $user]);
        DB::table('messages')->insert(['conversation_id' => $conversation, 'sender_id' => $users['parent'], 'body' => 'Synthetic preserved message']);
        $template = DB::table('fee_templates')->insertGetId(['fee_type_id' => DB::table('fee_types')->value('id'), 'name' => 'QA fee', 'amount_millimes' => 100000, 'academic_year_id' => $year, 'due_date' => '2026-11-30', 'installment_plan' => '[]', 'created_by' => $users['admin']]);
        $fee = DB::table('student_fees')->insertGetId(['student_id' => $student, 'fee_template_id' => $template, 'academic_year_id' => $year, 'name' => 'QA assessed charge', 'amount_millimes' => 100000]);
        $installment = DB::table('student_installments')->insertGetId(['student_fee_id' => $fee, 'name' => 'QA installment', 'amount_millimes' => 100000, 'due_date' => '2026-11-30']);
        DB::table('student_payments')->insert(['student_id' => $student, 'student_installment_id' => $installment, 'payment_method_id' => DB::table('payment_methods')->value('id'), 'amount_millimes' => 25000, 'paid_on' => '2026-11-01', 'request_key' => (string) Str::uuid(), 'recorded_by' => $users['admin']]);
        foreach (['sent', 'pending', 'queued'] as $state) DB::table('fcm_notification_events')->insert(['user_id' => $users['student'], 'event_key' => 'qa:r2:'.$state, 'payload' => null, 'queued_at' => $state === 'queued' ? $when : null, 'sent_at' => $state === 'sent' ? $when : null, 'created_at' => $when, 'updated_at' => $when]);
        $schema = $columns();
        unset($schema['migrations']);
        $save('fixtures', ['users' => $users, 'teacher' => $teacher, 'student' => $student, 'lesson' => $lesson, 'file' => $file, 'file_sha256' => hash('sha256', Storage::disk('local')->get($file)), 'schema' => $schema, 'legacy_snapshot' => $snapshot($schema)]);
        $assert(DB::table('exam_answers')->count() === 1 && DB::table('student_payments')->count() === 1 && DB::table('messages')->count() === 1, 'saved answers grades uploads messages and partial payment seeded');
    });
} elseif ($phase === 'upgrade') {
    $fixture = $read('fixtures');
    $assert($snapshot($fixture['schema']) === $fixture['legacy_snapshot'], 'all baseline table hashes unchanged after backup');
    $migrate();
    $assert(DB::table('migrations')->count() === 46, 'exactly two additive migrations applied');
    $assert($snapshot($fixture['schema']) === $fixture['legacy_snapshot'], 'every preexisting field and row retained after both migrations');
    $assert(DB::table('fcm_notification_events')->whereNotNull('cancelled_at')->count() === 0 && DB::table('fcm_notification_events')->whereNotNull('cancellation_reason')->count() === 0, 'no legacy event cancellation fabricated');
    $assert(DB::table('lesson_save_requests')->count() === 0 && DB::table('jobs')->count() === 0, 'no historical receipts or queue jobs backfilled');
    $assert(app(App\Services\FcmNotificationService::class)->retryPending() === 0 && DB::table('jobs')->count() === 0, 'legacy events lacking durable payload not replayed');
    $assert(hash('sha256', Storage::disk('local')->get($fixture['file'])) === $fixture['file_sha256'], 'existing attachment bytes retained');
    $key = (string) Str::uuid();
    DB::table('lesson_save_requests')->insert(['request_key' => $key, 'teacher_id' => $fixture['teacher'], 'lesson_id' => $fixture['lesson'], 'created_at' => now()]);
    $expectConstraint('23505', fn () => DB::table('lesson_save_requests')->insert(['request_key' => $key, 'teacher_id' => $fixture['teacher'], 'lesson_id' => $fixture['lesson'], 'created_at' => now()]), 'lesson replay receipt UUID unique on PostgreSQL');
    $expectConstraint('23503', fn () => DB::table('lesson_save_requests')->insert(['request_key' => (string) Str::uuid(), 'teacher_id' => $fixture['teacher'], 'lesson_id' => 999999999, 'created_at' => now()]), 'receipt rejects unknown lesson foreign key');
    DB::table('fcm_notification_events')->insert(['user_id' => $fixture['users']['student'], 'event_key' => 'qa:r2:cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'announcement_withdrawn', 'created_at' => now(), 'updated_at' => now()]);
    $beforeRefusal = $snapshot();
    $migration = require database_path('migrations/'.$newFiles[0]);
    try { $migration->down(); throw new LogicException('Cancellation history discarded.'); }
    catch (RuntimeException $exception) { $assert(str_contains($exception->getMessage(), 'cancellation history'), 'rollback refuses to discard cancellation history'); }
    $assert($snapshot() === $beforeRefusal, 'refused rollback leaves all tables unchanged');
    $migrate();
    $assert($snapshot() === $beforeRefusal && DB::table('migrations')->count() === 46, 'repeat migration is a no-op');
    $save('upgraded-snapshot', $snapshot());
} else {
    $fixture = $read('fixtures');
    $assert($snapshot() === $read('upgraded-snapshot'), 'pg_restore retained all table row counts and SHA256 values');
    $assert(hash('sha256', Storage::disk('local')->get($fixture['file'])) === $fixture['file_sha256'], 'persistent uploaded file remains separate from database restore');
    $assert(DB::table('lesson_save_requests')->count() === 1 && DB::table('fcm_notification_events')->whereNotNull('cancelled_at')->count() === 1, 'new receipt and cancellation history restored');
    $max = DB::table('messages')->max('id');
    DB::beginTransaction();
    try {
        $next = DB::table('messages')->insertGetId(['conversation_id' => DB::table('conversations')->value('id'), 'sender_id' => $fixture['users']['parent'], 'body' => 'Synthetic sequence probe']);
        $assert($next > $max, 'restored message sequence allocates an unused ID');
    } finally { DB::rollBack(); }
    $assert($snapshot() === $read('upgraded-snapshot'), 'rolled-back sequence probe leaves restored rows unchanged');
}
Http::assertNothingSent();
$result = ['phase' => $phase, 'result' => 'PASS', 'engine' => DB::selectOne('select version() as version')->version, 'database' => $db['database'], 'checks' => $checks, 'duration_seconds' => round(microtime(true) - $started, 3)];
$save($phase.'-result', $result);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
