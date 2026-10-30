<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\LessonUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonResumableUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $this->owner->id, 'status' => 'active']);
        $this->actingAs($this->owner);
    }

    private function start(int $bytes, string $name = 'lesson.pdf'): array
    {
        return $this->postJson(route('teacher.lessons.uploads.start'), ['name' => $name, 'size' => $bytes])
            ->assertCreated()->json();
    }

    private function chunk(string $id, int $index, string $bytes)
    {
        $path = tempnam(sys_get_temp_dir(), 'lesson-chunk-');
        file_put_contents($path, $bytes);
        try {
            return $this->post(route('teacher.lessons.uploads.chunk', $id), [
                'index' => $index, 'chunk' => new UploadedFile($path, 'chunk.bin', null, null, true),
            ], ['Accept' => 'application/json']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function ready(): array
    {
        $bytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
        $state = $this->start(strlen($bytes));
        $this->chunk($state['upload_id'], 0, $bytes)->assertOk();

        return $this->postJson(route('teacher.lessons.uploads.complete', $state['upload_id']))->assertOk()->assertJsonPath('status', 'ready')->json();
    }

    public function test_old_110_mib_rule_rejects_the_required_120_mib_file(): void
    {
        $file = UploadedFile::fake()->create('lesson.mp4', 120 * 1024, 'video/mp4');
        $validator = validator(['attachment' => $file], ['attachment' => 'file|max:112640']);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('Max', $validator->failed()['attachment']);
    }

    public function test_access_control_and_limit_and_idempotent_start(): void
    {
        $id = (string) Str::uuid();
        $payload = ['upload_id' => $id, 'name' => 'lesson.mp4', 'size' => 120 * 1024 * 1024];
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)->assertCreated();
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)->assertCreated()->assertJsonPath('upload_id', $id);
        $this->assertDatabaseCount('lesson_uploads', 1);
        $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'large.mp4', 'size' => 264 * 1024 * 1024 + 1])->assertUnprocessable()->assertJsonValidationErrors('size');
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        $this->actingAs($other)->getJson(route('teacher.lessons.uploads.status', $id))->assertNotFound();
        $this->chunk($id, 0, 'x')->assertNotFound();
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertNotFound();
        $this->deleteJson(route('teacher.lessons.uploads.cancel', $id))->assertNotFound();
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->actingAs($student)->postJson(route('teacher.lessons.uploads.start'), $payload)->assertForbidden();
        $this->actingAs($this->owner);
        config(['permissions.roles.teacher' => []]);
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)->assertForbidden();
    }

    public function test_exact_video_boundary_is_allowed_and_larger_videos_are_rejected(): void
    {
        $this->assertSame(264 * 1024 * 1024, config('lesson_uploads.video_max_bytes'));
        $this->start(264 * 1024 * 1024, 'boundary.MP4');
        $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'too-large.mp4', 'size' => 264 * 1024 * 1024 + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('size')->assertSee('264');
        $this->start(512 * 1024 * 1024, 'document.pdf');
        $this->assertDatabaseCount('lesson_uploads', 2);
    }

    public function test_resume_retries_and_crash_recovery_preserve_bytes(): void
    {
        $chunk = config('lesson_uploads.chunk_bytes');
        $bytes = str_pad('%PDF-1.4', $chunk, 'x');
        $state = $this->start($chunk + 5);
        $id = $state['upload_id'];
        $this->chunk($id, 1, 'later')->assertConflict();
        $this->chunk($id, 0, $bytes)->assertOk()->assertJsonPath('received_bytes', $chunk);
        $this->chunk($id, 0, $bytes)->assertOk()->assertJsonPath('received_bytes', $chunk);
        $this->chunk($id, 0, str_repeat('z', $chunk))->assertConflict();
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
        $path = Storage::disk('local')->path('lesson-uploads/'.$id.'/body.part');
        file_put_contents($path, 'crashed-write', FILE_APPEND);
        $this->getJson(route('teacher.lessons.uploads.status', $id))->assertOk()->assertJsonPath('received_bytes', $chunk);
        $this->chunk($id, 1, 'later')->assertOk();
        $this->assertSame(hash('sha256', $bytes.'later'), hash_file('sha256', $path));
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertOk()->assertJsonPath('status', 'ready');
        file_put_contents($path, 'z', FILE_APPEND);
        $this->assertSame($chunk + 6, filesize($path));
    }

    public function test_content_validation_detects_fake_video_and_disk_corruption(): void
    {
        $id = $this->start(5, 'fake.mp4')['upload_id'];
        $this->chunk($id, 0, 'hello')->assertOk();
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
        $bytes = '%PDF-1.4 hello';
        $id = $this->start(strlen($bytes))['upload_id'];
        $this->chunk($id, 0, $bytes)->assertOk();
        file_put_contents(Storage::disk('local')->path('lesson-uploads/'.$id.'/body.part'), '%PDF-1.4 other');
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
    }

    public function test_claim_is_atomic_and_lesson_rollback_keeps_upload_available(): void
    {
        $state = $this->ready();
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Biology', 'code' => 'UPLOAD', 'stage' => 'Basic', 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->owner->teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id]);
        $payload = ['submission_key' => (string) Str::uuid(), 'classroom_id' => $room->id, 'subject_id' => $subject->id,
            'title' => 'Upload lesson', 'status' => 'draft', 'upload_ids' => [$state['upload_id']]];
        $this->post(route('teacher.lessons.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::where('submission_key', $payload['submission_key'])->firstOrFail();
        $this->assertSame(1, $lesson->attachments()->count());
        $this->get(route('teacher.lessons.attachments.download', [$lesson, $lesson->attachments->first()->id]))->assertOk();
        $this->post(route('teacher.lessons.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $lesson->attachments()->count());
        $this->deleteJson(route('teacher.lessons.uploads.cancel', $state['upload_id']))->assertConflict();
        DB::table('lesson_uploads')->where('id', $state['upload_id'])->update(['expires_at' => now()->subDay()]);
        app(LessonUploadService::class)->prune();
        Storage::disk('local')->assertExists($lesson->attachments->first()->file_path);

        $second = $this->ready();
        DB::beginTransaction();
        app(LessonUploadService::class)->attach($second['upload_id'], $this->owner->id, $lesson);
        DB::rollBack();
        $this->getJson(route('teacher.lessons.uploads.status', $second['upload_id']))->assertJsonPath('status', 'ready');
        $this->assertSame(1, $lesson->attachments()->count());
    }

    public function test_expiry_cancel_and_cleanup(): void
    {
        $state = $this->ready();
        $this->deleteJson(route('teacher.lessons.uploads.cancel', $state['upload_id']))->assertOk();
        Storage::disk('local')->assertMissing('lesson-uploads/'.$state['upload_id'].'/body.part');
        $state = $this->ready();
        DB::table('lesson_uploads')->where('id', $state['upload_id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson(route('teacher.lessons.uploads.status', $state['upload_id']))->assertStatus(410);
        app(LessonUploadService::class)->prune();
        Storage::disk('local')->assertMissing('lesson-uploads/'.$state['upload_id']);
        $this->assertDatabaseMissing('lesson_uploads', ['id' => $state['upload_id']]);
    }

    public static function largeSizes(): array
    {
        return [[10], [120], [250], [264]];
    }

    #[DataProvider('largeSizes')]
    public function test_real_video_chunks_keep_size_hash_and_decode(int $mib): void
    {
        $directory = getenv('LESSON_VIDEO_FIXTURES');
        if (! $directory || ! is_file($directory.'/'.$mib.'MiB.mp4')) {
            $this->markTestSkipped('Set LESSON_VIDEO_FIXTURES to generated playable MP4 fixtures.');
        }
        $source = $directory.'/'.$mib.'MiB.mp4';
        $size = filesize($source);
        $state = $this->start($size, $mib.'MiB.mp4');
        $stream = fopen($source, 'rb');
        $started = microtime(true);
        try {
            for ($index = 0; ! feof($stream); $index++) {
                $bytes = fread($stream, $state['chunk_size']);
                if ($bytes === '') {
                    break;
                }
                $this->chunk($state['upload_id'], $index, $bytes)->assertOk();
            }
        } finally {
            fclose($stream);
        }
        $result = $this->postJson(route('teacher.lessons.uploads.complete', $state['upload_id']))->assertOk()->assertJsonPath('status', 'ready')->json();
        $path = Storage::disk('local')->path('lesson-uploads/'.$state['upload_id'].'/body.part');
        $this->assertSame($size, filesize($path));
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $path));
        $this->assertSame(hash_file('sha256', $source), $result['sha256']);
        $ffmpeg = getenv('LESSON_TEST_FFMPEG');
        if ($ffmpeg) {
            $process = proc_open([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-i', $path, '-f', 'null', '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
        }
        fwrite(STDERR, sprintf("\nVIDEO %d MiB: %d bytes, %.3fs, SHA-256 %s\n", $mib, $size, microtime(true) - $started, $result['sha256']));
    }
}
