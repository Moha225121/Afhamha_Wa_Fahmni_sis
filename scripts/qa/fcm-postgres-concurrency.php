<?php

// Synthetic, marked-loopback PostgreSQL only. Invoked by its guarded PS runner.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(dirname(__DIR__, 2).'/tests/Fixtures/no-environment');
$app->loadEnvironmentFrom('.env.disabled-for-tests');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$config = $db->getConfig();
if (! $app->environment('testing') || getenv('QA_ALLOW_LOCAL_POSTGRES') !== '1'
    || $db->getDriverName() !== 'pgsql' || ($config['host'] ?? '') !== '127.0.0.1'
    || ($config['database'] ?? '') !== 'afhamha_testing' || ($config['username'] ?? '') !== 'qa_release_owner'
    || (int) ($config['port'] ?? 0) < 55439 || (int) ($config['port'] ?? 0) > 55459) {
    throw new RuntimeException('Refusing FCM concurrency verification outside the dedicated PostgreSQL fixture.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['cache.default' => 'database', 'queue.connections.database.connection' => 'pgsql',
    'services.firebase.project_id' => 'qa-synthetic', 'services.firebase.credentials' => null,
    'services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
$mode = $argv[1] ?? 'coordinate';
$directory = storage_path('app/fcm-race');
if (! is_dir($directory)) mkdir($directory, 0700, true);
$providerLog = $directory.'/accepted.ndjson';
$app->instance(App\Contracts\FcmAccessTokenProvider::class, new class implements App\Contracts\FcmAccessTokenProvider {
    public function getAccessToken(): ?string { return 'synthetic-only'; }
});
Illuminate\Support\Facades\Http::fake(['https://fcm.googleapis.com/*' => function ($request) use ($providerLog, $mode) {
    file_put_contents($providerLog, json_encode(['event' => $request['message']['data']['event_id'], 'pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);
    if ($mode === 'crash') exit(17); // Simulated process exit after provider acceptance, before its response.
    usleep(250000);
    return Illuminate\Support\Facades\Http::response(['name' => 'synthetic-accepted'], 200);
}]);

if ($mode !== 'coordinate') {
    [$unused, $mode, $userId, $eventKey, $queue, $slot] = $argv;
    config(['queue.connections.database.queue' => $queue]);
    touch($directory.'/ready-'.$slot);
    $deadline = microtime(true) + 15;
    while (! is_file($directory.'/go') && microtime(true) < $deadline) usleep(10000);
    if (! is_file($directory.'/go')) throw new RuntimeException('QA rendezvous timed out.');
    if ($mode === 'enqueue') {
        app(App\Services\FcmNotificationService::class)->record(App\Models\User::findOrFail((int) $userId), $eventKey,
            ['title' => 'Synthetic concurrency notice', 'body' => 'QA only']);
    } elseif (in_array($mode, ['work', 'crash'], true)) {
        Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0]);
    } else throw new RuntimeException('Unknown QA child mode.');
    exit(0);
}

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$run = Illuminate\Support\Str::uuid()->toString();
$queue = 'qa-fcm-'.$run;
config(['queue.connections.database.queue' => $queue]);
$user = App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active', 'email' => 'fcm-race-'.$run.'@example.test']);
$token = 'qa-synthetic-device-'.$run;
$user->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
$eventKey = 'qa:race:'.$run;
$runPair = function (string $mode) use ($directory, $user, $eventKey, $queue): void {
    foreach (['go', 'ready-1', 'ready-2'] as $file) if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
    $processes = [];
    foreach ([1, 2] as $slot) {
        $process = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, $mode, (string) $user->id, $eventKey, $queue, (string) $slot], base_path());
        $process->setTimeout(30); $process->start(); $processes[] = $process;
    }
    $deadline = microtime(true) + 15;
    while ((! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) && microtime(true) < $deadline) usleep(10000);
    touch($directory.'/go');
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) throw new RuntimeException('QA child failed: '.$process->getErrorOutput().$process->getOutput());
    }
};
$runPair('enqueue');
$event = Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('user_id', $user->id)->first();
$checks = [
    'one_notification_after_two_source_processes' => $user->notifications()->count() === 1,
    'one_event_after_two_source_processes' => Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('user_id', $user->id)->count() === 1,
    'one_job_after_concurrent_enqueue' => Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->count() === 1,
];
// Deliberately duplicate the same job to exercise the real shared-cache middleware.
Illuminate\Support\Facades\Queue::connection('database')->push((new App\Jobs\SendFcmNotification($user->id, $eventKey, $event->id,
    json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR)))->beforeCommit(), '', $queue);
$runPair('work');
Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->update(['available_at' => now()->subSecond()->timestamp]);
Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0]);
$checks['one_mock_provider_acceptance_across_two_workers_and_duplicate_retry'] = count(file($providerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === 1;
$checks['one_delivery_receipt'] = Illuminate\Support\Facades\DB::table('fcm_notification_deliveries')->where('event_id', $event->id)->count() === 1;
$checks['event_processing_complete'] = Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $event->id)->value('sent_at') !== null;
$checks['queue_drained'] = ! Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->exists();
$crashKey = $eventKey.':crash';
app(App\Services\FcmNotificationService::class)->record($user, $crashKey, ['title' => 'Synthetic restart notice', 'body' => 'QA only']);
$crashEvent = Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('event_key', $crashKey)->first();
$crashProcess = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'crash', (string) $user->id, $crashKey, $queue, '3'], base_path());
$crashProcess->setTimeout(30); $crashProcess->run();
$checks['process_exit_after_mock_acceptance_preserves_reserved_job'] = $crashProcess->getExitCode() === 17
    && Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->whereNotNull('reserved_at')->count() === 1
    && Illuminate\Support\Facades\DB::table('fcm_notification_events')->where('id', $crashEvent->id)->value('sent_at') === null;
// Advance the reservation expiry explicitly instead of waiting 90 real seconds.
Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->update(['reserved_at' => now()->subSeconds(91)->timestamp]);
$restart = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'work', (string) $user->id, $crashKey, $queue, '4'], base_path());
$restart->setTimeout(30); $restart->mustRun();
$checks['restart_waits_for_surviving_overlap_lock'] = Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->count() === 1
    && Illuminate\Support\Facades\DB::table('fcm_notification_deliveries')->where('event_id', $crashEvent->id)->count() === 0;
// Simulate the remaining 120-second lock TTL without waiting in the QA run.
$crashedJob = new App\Jobs\SendFcmNotification($user->id, $crashKey, $crashEvent->id, json_decode($crashEvent->payload, true, 512, JSON_THROW_ON_ERROR));
$lockKey = config('cache.prefix').$crashedJob->middleware()[0]->getLockKey($crashedJob);
Illuminate\Support\Facades\DB::table('cache_locks')->where('key', $lockKey)->update(['expiration' => now()->subSecond()->timestamp]);
Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->update(['available_at' => now()->subSecond()->timestamp]);
$restartAfterExpiry = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, 'work', (string) $user->id, $crashKey, $queue, '5'], base_path());
$restartAfterExpiry->setTimeout(30); $restartAfterExpiry->mustRun();
$acceptances = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($providerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$checks['restarted_process_recovers_with_two_possible_provider_acceptances'] = count(array_filter($acceptances, fn ($row) => (int) $row['event'] === (int) $crashEvent->id)) === 2
    && Illuminate\Support\Facades\DB::table('fcm_notification_deliveries')->where('event_id', $crashEvent->id)->count() === 1
    && ! Illuminate\Support\Facades\DB::table('jobs')->where('queue', $queue)->exists();
$report = ['tested_at' => gmdate(DATE_ATOM), 'engine' => $db->selectOne('select version() as version')->version,
    'scope' => 'Two local PHP processes, PostgreSQL row locks and database cache locks; mocked provider only', 'checks' => $checks];
file_put_contents(base_path('docs/qa/evidence/fcm-postgres-concurrency.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit(in_array(false, $checks, true) ? 1 : 0);
