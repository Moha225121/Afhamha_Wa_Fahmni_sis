<?php

// Run separately against a dedicated local PostgreSQL lesson_upload_race_testing database.
// This exercises a real cross-process row-lock wait, which SQLite cannot reproduce.
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\LessonUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)
    || getenv('DB_DATABASE') !== 'lesson_upload_race_testing' || getenv('DB_URL')) {
    throw new RuntimeException('Only a dedicated local lesson_upload_race_testing database is allowed.');
}
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'lesson_upload_race_testing'
    || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)
    || config('database.connections.pgsql.url')) {
    throw new RuntimeException('Unexpected database configuration.');
}
$worker = ($argv[1] ?? '') === 'complete-worker';
$run = $worker ? ($argv[4] ?? '') : (string) Str::uuid();
if (! Str::isUuid($run)) {
    throw new RuntimeException('Invalid test run identifier.');
}
$directory = storage_path('framework/testing/disks/lesson-claim-race-'.$run);
config(['filesystems.disks.local.root' => $directory]);
$service = app(LessonUploadService::class);
if ($worker) {
    DB::selectOne('select set_config(?, ?, false)', ['application_name', 'lesson_claim_race_'.$run]);
    try {
        $state = $service->complete($argv[2], (int) $argv[3]);
        $result = ['http' => 200, 'status' => $state['status']];
    } catch (HttpExceptionInterface $exception) {
        $result = ['http' => $exception->getStatusCode()];
    }
    file_put_contents($directory.'/worker-result.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}
$fixture = getenv('LESSON_VIDEO_FIXTURES').'/seed.mp4';
if (! is_file($fixture) || filesize($fixture) > config('lesson_uploads.chunk_bytes')) {
    throw new RuntimeException('Generate the small MP4 fixture and set LESSON_VIDEO_FIXTURES first.');
}
Artisan::call('migrate', ['--force' => true]);
$owner = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
$teacher = Teacher::create(['user_id' => $owner->id, 'status' => 'active']);
$year = AcademicYear::create(['name' => 'Claim race '.$run, 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
$room = Classroom::create(['name' => 'Race class', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
$subject = Subject::create(['name' => 'Race subject', 'code' => 'RACE-'.Str::random(8), 'stage' => 'Basic', 'status' => 'active']);
$lesson = Lesson::create(['teacher_id' => $teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'title' => 'Claim race test', 'status' => 'draft']);
$state = $service->start($owner->id, 'race.mp4', filesize($fixture));
$id = $state['upload_id'];
$service->chunk($id, $owner->id, 0, new UploadedFile($fixture, 'chunk.bin', null, null, true));
$service->complete($id, $owner->id);
$process = new Process([PHP_BINARY, __FILE__, 'complete-worker', $id, (string) $owner->id, $run]);
$process->setTimeout(20);
$waiting = false;
try {
    DB::beginTransaction();
    $service->attach($id, $owner->id, $lesson);
    $process->start();
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        DB::select('select pg_stat_clear_snapshot()');
        if (DB::selectOne('select count(*) as n from pg_stat_activity where application_name=? and wait_event_type=?', ['lesson_claim_race_'.$run, 'Lock'])->n > 0) {
            $waiting = true;
            break;
        }
        usleep(20000);
    }
    if (! $waiting) {
        throw new RuntimeException('Completion did not reach the expected database row lock.');
    }
    DB::commit();
    $process->wait();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('The completion worker failed.');
    }
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($process->isRunning()) {
        $process->stop(0);
    }
}
$workerResult = json_decode(file_get_contents($directory.'/worker-result.json'), true, 512, JSON_THROW_ON_ERROR);
$row = DB::table('lesson_uploads')->where('id', $id)->first();
$references = DB::table('lesson_attachments')->where('file_path', 'lesson-uploads/'.$id.'/body.part')->count();
$fileUnchanged = hash_equals(hash_file('sha256', $fixture), hash_file('sha256', Storage::disk('local')->path('lesson-uploads/'.$id.'/body.part')));
$safe = $row->status === 'attached' && $row->lesson_id === $lesson->id && $references === 1 && $fileUnchanged && $workerResult['http'] === 409;
echo json_encode(['concurrent_complete_waited_on_claim' => $waiting, 'worker' => $workerResult, 'final_upload_status' => $row->status,
    'single_attachment' => $references === 1, 'original_lesson_retained' => $row->lesson_id === $lesson->id, 'file_unchanged' => $fileUnchanged, 'safe' => $safe], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($safe ? 0 : 1);
