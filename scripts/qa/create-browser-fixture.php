<?php

// Creates a fresh disposable database; never drops or reuses a database.
$qaRoot = dirname(__DIR__, 2);
$qaRun = $qaRoot.'/tmp/qa-browser/'.bin2hex(random_bytes(8));
mkdir($qaRun, 0700, true);
foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'app/public'] as $directory) {
    mkdir($qaRun.'/storage/'.$directory, 0700, true);
}
$handle = fopen($qaRun.'/qa.sqlite', 'x');
if ($handle === false) {
    throw new RuntimeException('Refusing to reuse a QA database.');
}
fclose($handle);
putenv('QA_BROWSER_RUN='.$qaRun);
$app = require __DIR__.'/browser-bootstrap.php';
if (config('database.default') === 'pgsql'
    && Illuminate\Support\Facades\DB::selectOne("SELECT count(*) AS count FROM pg_tables WHERE schemaname = 'public'")->count > 0) {
    throw new RuntimeException('The dedicated PostgreSQL browser database is not empty; refusing to seed or reuse it.');
}
if (Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('QA schema creation failed.');
}
if (Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\LocalDemoSeeder::class, '--force' => true]) !== 0) {
    throw new RuntimeException('QA synthetic fixture creation failed.');
}

$year = App\Models\AcademicYear::firstOrFail();
$otherClass = App\Models\Classroom::create(['name' => 'الصف الثاني', 'stage' => 'أساسي', 'section' => 'ب', 'academic_year_id' => $year->id]);
$second = App\Models\Student::where('student_number', 'S-1002')->firstOrFail();
$second->update(['classroom_id' => $otherClass->id]);
$student = App\Models\Student::where('student_number', 'S-1001')->firstOrFail();
$teacher = App\Models\Teacher::firstOrFail();
$subject = App\Models\Subject::firstOrFail();
$otherSubject = App\Models\Subject::create(['name' => 'علوم اصطناعية', 'code' => 'QA-SCI', 'status' => 'active', 'stage' => 'أساسي']);
$student->classroom->subjects()->syncWithoutDetaching([$otherSubject->id]);
$otherClass->subjects()->sync([$subject->id, $otherSubject->id]);
Illuminate\Support\Facades\DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $otherClass->id, 'subject_id' => $subject->id]);
$parentOne = App\Models\User::factory()->create(['name' => 'ولي أمر اصطناعي واحد', 'email' => 'qa-parent-one@example.test', 'role' => 'parent', 'status' => 'active', 'password' => Illuminate\Support\Facades\Hash::make('password123')]);
$guardian = App\Models\Guardian::create(['user_id' => $parentOne->id, 'status' => 'active']);
$guardian->students()->attach($student->id);
$outsider = App\Models\User::factory()->create(['name' => 'طالب غير مرتبط اصطناعي', 'email' => 'qa-outsider@example.test', 'role' => 'student', 'status' => 'active', 'password' => Illuminate\Support\Facades\Hash::make('password123')]);
$outsideClass = App\Models\Classroom::create(['name' => 'صف خارج الجمهور', 'stage' => 'أساسي', 'section' => 'ج', 'academic_year_id' => $year->id]);
App\Models\Student::create(['user_id' => $outsider->id, 'student_number' => 'QA-OUT', 'classroom_id' => $outsideClass->id, 'status' => 'active']);
$unit = App\Models\Unit::create(['classroom_id' => $student->classroom_id, 'subject_id' => $subject->id, 'title' => 'وحدة اصطناعية', 'position' => 1]);
$lesson = App\Models\Lesson::create(['teacher_id' => $teacher->id, 'classroom_id' => $student->classroom_id, 'subject_id' => $subject->id, 'unit_id' => $unit->id, 'title' => 'درس QA منشور', 'content' => '<p>محتوى درس اصطناعي للتحقق من العرض.</p>', 'status' => 'published', 'published_at' => now()->subHour(), 'position' => 1]);
Illuminate\Support\Facades\Storage::disk('local')->put('qa/lesson.txt', 'Synthetic QA attachment');
$lesson->attachments()->create(['title' => 'مرفق اصطناعي', 'disk' => 'local', 'file_path' => 'qa/lesson.txt', 'original_name' => 'qa-lesson.txt', 'mime_type' => 'text/plain', 'size' => 23]);
file_put_contents($qaRun.'/fixture.json', json_encode(['student' => $student->id, 'classroom' => $student->classroom_id, 'subject' => $subject->id, 'other_subject' => $otherSubject->id, 'lesson' => $lesson->id, 'teacher' => $teacher->id, 'guardian' => $guardian->id], JSON_PRETTY_PRINT));
echo $qaRun.PHP_EOL;
