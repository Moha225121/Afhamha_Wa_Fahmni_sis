<?php

// Requires the existing guarded, synthetic browser fixture and its marked
// PostgreSQL descriptor. Never loads .env or connects to a remote database.
$app = require __DIR__.'/browser-bootstrap.php';
use Illuminate\Support\Facades\DB;
use App\Services\ResultPublicationService;

if (DB::connection()->getDriverName() !== 'pgsql') throw new RuntimeException('This check requires marked local PostgreSQL.');
$runId = $argv[3] ?? (string) Illuminate\Support\Str::uuid();
if (! Illuminate\Support\Str::isUuid($runId)) throw new RuntimeException('Invalid run identifier.');
$directory = storage_path('app/result-concurrency/'.$runId);
if (! is_dir($directory)) mkdir($directory, 0700, true);
$mode = $argv[1] ?? 'coordinate';
if ($mode !== 'coordinate') {
    if (! in_array($mode, ['save', 'import', 'competing'], true)) throw new RuntimeException('Unknown child mode.');
    $data = json_decode(file_get_contents($directory.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $slot = (int) ($argv[2] ?? 0);
    if (! in_array($slot, [1, 2], true)) throw new RuntimeException('Invalid child slot.');
    auth()->loginUsingId($data['admin']);
    touch($directory.'/ready-'.$mode.'-'.$slot);
    $deadline = microtime(true) + 30;
    while (! is_file($directory.'/go-'.$mode) && microtime(true) < $deadline) usleep(10000);
    if (! is_file($directory.'/go-'.$mode)) throw new RuntimeException('Rendezvous timeout.');
    if ($mode === 'save') app(ResultPublicationService::class)->saveMark($data['period'], $data['mark']);
    elseif ($mode === 'import') app(ResultPublicationService::class)->importExams($data['period'], [$data['exam']]);
    else {
        try {
            app(ResultPublicationService::class)->importExams($data['competing_periods'][$slot - 1], [$data['competing_exam']]);
            $outcome = 'imported';
        } catch (Illuminate\Validation\ValidationException $exception) {
            if (! isset($exception->errors()['exams'])) throw $exception;
            $outcome = 'linked_elsewhere';
        }
        file_put_contents($directory.'/outcome-'.$slot, $outcome);
    }
    exit(0);
}
if (is_file($directory.'/fixture.json')) throw new RuntimeException('Use a fresh QA_BROWSER_RUN for another concurrency run.');
$student = App\Models\Student::where('student_number', 'S-1001')->firstOrFail();
$subject = $student->classroom->subjects()->firstOrFail();
$admin = App\Models\User::where('email', 'admin@example.test')->firstOrFail();
auth()->login($admin);
$period = DB::table('exam_periods')->insertGetId(['name' => 'Synthetic R2 concurrent results',
    'academic_year_id' => $student->classroom->academic_year_id, 'classroom_id' => $student->classroom_id,
    'term' => 'R2', 'exam_type' => 'final', 'status' => 'draft', 'created_by' => $admin->id,
    'created_at' => now(), 'updated_at' => now()]);
$exam = App\Models\Exam::create(['title' => 'Synthetic concurrency exam', 'teacher_id' => App\Models\Teacher::firstOrFail()->id,
    'subject_id' => $subject->id, 'classroom_id' => $student->classroom_id,
    'status' => 'published', 'starts_at' => now()->subDay(), 'duration_minutes' => 30, 'total_score' => 20]);
DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => $student->id, 'score' => 17, 'created_at' => now(), 'updated_at' => now()]);
$data = ['admin' => $admin->id, 'period' => $period, 'exam' => $exam->id,
    'mark' => ['student_id' => $student->id, 'subject_id' => $subject->id, 'score' => 12, 'maximum_score' => 20]];
file_put_contents($directory.'/fixture.json', json_encode($data));
$pair = function (string $mode) use ($directory, $runId): void {
    $children = [];
    foreach ([1, 2] as $slot) {
        $child = new Symfony\Component\Process\Process([PHP_BINARY, __FILE__, $mode, (string) $slot, $runId], base_path());
        $child->setTimeout(45); $child->start(); $children[] = $child;
    }
    $deadline = microtime(true) + 30;
    while ((! is_file($directory.'/ready-'.$mode.'-1') || ! is_file($directory.'/ready-'.$mode.'-2')) && microtime(true) < $deadline) usleep(10000);
    if (! is_file($directory.'/ready-'.$mode.'-1') || ! is_file($directory.'/ready-'.$mode.'-2')) throw new RuntimeException('Children did not reach barrier.');
    touch($directory.'/go-'.$mode);
    foreach ($children as $child) { $child->wait(); if (! $child->isSuccessful()) throw new RuntimeException($child->getErrorOutput().$child->getOutput()); }
};
$pair('save');
$checks = ['concurrent_same_identity_save_creates_one_mark' => DB::table('result_marks')->where('exam_period_id', $period)->count() === 1];
$pair('import');
$marks = DB::table('result_marks')->where('exam_period_id', $period);
$checks['concurrent_reimport_creates_one_mark'] = (clone $marks)->count() === 1 && (float) (clone $marks)->value('score') === 17.0;
$otherSubject = App\Models\Subject::create(['name' => 'مادة تحقق مستقلة', 'code' => 'R2-'.substr($runId, 0, 8), 'stage' => $student->classroom->stage, 'status' => 'active']);
$student->classroom->subjects()->attach($otherSubject->id);
app(ResultPublicationService::class)->saveMark($period, array_replace($data['mark'], ['subject_id' => $otherSubject->id]));
$checks['distinct_subject_preserved'] = (clone $marks)->count() === 2;
$checks['source_grade_and_exam_preserved'] = DB::table('grades')->where('exam_id', $exam->id)->count() === 1 && $exam->fresh()->exists;
$competingExam = $exam->replicate(['exam_period_id']);
$competingExam->title = 'Synthetic competing-period exam'; $competingExam->save();
DB::table('grades')->insert(['exam_id' => $competingExam->id, 'student_id' => $student->id, 'score' => 18, 'created_at' => now(), 'updated_at' => now()]);
$data['competing_exam'] = $competingExam->id;
$data['competing_periods'] = [];
foreach ([1, 2] as $slot) {
    $record = (array) DB::table('exam_periods')->find($period); unset($record['id']);
    $record['name'] = 'Synthetic competing period '.$slot;
    $data['competing_periods'][] = DB::table('exam_periods')->insertGetId($record);
}
file_put_contents($directory.'/fixture.json', json_encode($data));
// A scoped test trigger lengthens the final write boundary, making the old
// read-before-lock race deterministic without changing the application code.
$trigger = 'qa_r2_hold_'.str_replace('-', '', substr($runId, 0, 8));
DB::unprepared('CREATE FUNCTION '.$trigger.'() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.3); RETURN NEW; END $$');
DB::unprepared('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON exams FOR EACH ROW WHEN (OLD.id = '.(int) $competingExam->id.') EXECUTE FUNCTION '.$trigger.'()');
try { $pair('competing'); }
finally { DB::unprepared('DROP TRIGGER '.$trigger.' ON exams'); DB::unprepared('DROP FUNCTION '.$trigger.'()'); }
$outcomes = [file_get_contents($directory.'/outcome-1'), file_get_contents($directory.'/outcome-2')]; sort($outcomes);
$checks['competing_period_import_has_one_winner_and_explicit_rejection'] = $outcomes === ['imported', 'linked_elsewhere'];
$checks['competing_periods_have_one_official_mark_total'] = DB::table('result_marks')->whereIn('exam_period_id', $data['competing_periods'])->count() === 1;
$winningPeriod = $competingExam->fresh()->exam_period_id;
$checks['winning_exam_link_matches_sole_official_mark'] = DB::table('result_marks')->where('exam_period_id', $winningPeriod)->where('student_id', $student->id)->count() === 1;
$report = ['tested_at' => gmdate(DATE_ATOM), 'engine' => DB::selectOne('select version() as version')->version,
    'scope' => 'Two real PHP processes released together at a barrier; synthetic local PostgreSQL only. Competing-period case uses a scoped 300ms database trigger at the exam write boundary.', 'checks' => $checks];
$output = base_path('docs/qa/round-2/evidence');
if (! is_dir($output)) mkdir($output, 0700, true);
file_put_contents($output.'/result-concurrency.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
