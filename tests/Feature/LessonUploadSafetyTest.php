<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\LessonUploadService;
use App\Services\LessonVideoValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LessonUploadSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private array $lessonData;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $this->owner->id, 'status' => 'active']);
        $year = AcademicYear::create(['name' => 'Safety year', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $room = Classroom::create(['name' => 'Grade 7', 'section' => 'A', 'stage' => 'Basic', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'Safety', 'code' => 'SAFE', 'stage' => 'Basic', 'status' => 'active']);
        $room->subjects()->attach($subject);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id]);
        $this->lessonData = ['submission_key' => (string) Str::uuid(), 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'title' => 'Safety lesson', 'status' => 'draft'];
        $this->actingAs($this->owner);
    }

    public static function optionalLists(): array
    {
        return ['missing' => [false, null], 'null' => [true, null], 'empty' => [true, []]];
    }

    #[DataProvider('optionalLists')]
    public function test_optional_upload_ids_preserve_normal_lesson_creation(bool $present, mixed $value): void
    {
        $payload = $this->lessonData;
        if ($present) {
            $payload['upload_ids'] = $value;
        }
        $this->postJson(route('teacher.lessons.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function invalidLists(): array
    {
        return [['text'], [42], [true], [['bad-id']], [[null]], [[['id' => 'bad']]], [(object) ['id' => 'bad']]];
    }

    #[DataProvider('invalidLists')]
    public function test_unsupported_upload_id_values_return_validation_errors(mixed $value): void
    {
        $response = $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => $value]);
        $response->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 0);
    }

    private function staged(string $bytes, string $name = 'sample.mp4'): string
    {
        $service = app(LessonUploadService::class);
        $state = $service->start($this->owner->id, $name, strlen($bytes));
        $path = tempnam(sys_get_temp_dir(), 'lesson-safety-');
        file_put_contents($path, $bytes);
        try {
            $service->chunk($state['upload_id'], $this->owner->id, 0, new UploadedFile($path, 'chunk.bin', null, null, true));
        } finally {
            unlink($path);
        }

        return $state['upload_id'];
    }

    private function seedVideo(): string
    {
        return $this->fixtureBytes('seed.mp4');
    }

    private function fixtureBytes(string $name): string
    {
        $fixture = getenv('LESSON_VIDEO_FIXTURES').'/'.$name;
        if (! is_file($fixture)) {
            $this->markTestSkipped('Set LESSON_VIDEO_FIXTURES to synthetic MP4 fixtures.');
        }

        return file_get_contents($fixture);
    }

    public function test_legacy_and_direct_video_uploads_enforce_the_video_limit(): void
    {
        $oversized = fn () => UploadedFile::fake()->create('large.MP4', 264 * 1024 + 1, 'video/mp4');
        $this->postJson(route('teacher.lessons.attachments.upload'), ['attachment' => $oversized()])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment')->assertSee('264');
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['attachments' => [$oversized()]])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0')->assertSee('264');
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('lesson_attachments', 0);
    }

    private function paddedVideoPath(int $size): string
    {
        $seed = $this->seedVideo();
        $path = tempnam(sys_get_temp_dir(), 'lesson-video-boundary-');
        $stream = fopen($path, 'wb');
        try {
            $this->assertSame(strlen($seed), fwrite($stream, $seed));
            // A valid MP4 free box pads the fixture without allocating its body.
            $this->assertSame(8, fwrite($stream, pack('Na4', $size - strlen($seed), 'free')));
            $this->assertTrue(ftruncate($stream, $size));
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function test_renamed_mp4_above_video_limit_is_rejected_by_legacy_and_direct_uploads(): void
    {
        $path = $this->paddedVideoPath(264 * 1024 * 1024 + 1);
        try {
            $upload = fn () => new UploadedFile($path, 'lecture.pdf', 'application/pdf', null, true);
            $this->assertSame('application/pdf', $upload()->getClientMimeType());
            $this->assertSame('video/mp4', $upload()->getMimeType());

            $this->postJson(route('teacher.lessons.attachments.upload'), ['attachment' => $upload()])
                ->assertUnprocessable()->assertJsonValidationErrors('attachment')->assertSee('264');
            $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['attachments' => [$upload()]])
                ->assertUnprocessable()->assertJsonValidationErrors('attachments.0')->assertSee('264');
            $this->assertDatabaseCount('lessons', 0);
            $this->assertDatabaseCount('lesson_attachments', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            unlink($path);
        }
    }

    public function test_renamed_mp4_at_exact_video_limit_is_accepted_by_legacy_and_direct_uploads(): void
    {
        $size = 264 * 1024 * 1024;
        $path = $this->paddedVideoPath($size);
        try {
            $upload = fn () => new UploadedFile($path, 'lecture.pdf', 'application/pdf', null, true);
            $this->assertSame('video/mp4', $upload()->getMimeType());

            $this->postJson(route('teacher.lessons.attachments.upload'), ['attachment' => $upload()])
                ->assertCreated()->assertJsonPath('size', $size);
            $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['attachments' => [$upload()]])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('lesson_attachments', ['original_name' => 'lecture.pdf', 'mime_type' => 'video/mp4', 'size' => $size]);
        } finally {
            unlink($path);
        }
    }

    public function test_old_oversized_ready_upload_cannot_bypass_the_new_limit(): void
    {
        $id = $this->staged($this->seedVideo());
        DB::table('lesson_uploads')->where('id', $id)->update(['status' => 'ready', 'size' => 264 * 1024 * 1024 + 1]);
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => [$id]])
            ->assertUnprocessable()->assertJsonValidationErrors('upload_ids')->assertSee('264');
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'ready']);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_old_oversized_staging_token_is_rejected_and_its_file_is_preserved(): void
    {
        $token = (string) Str::uuid();
        $path = 'lesson-staging/'.$this->owner->id.'/'.$token.'.mp4';
        $disk = Storage::disk('local');
        $disk->put($path, $this->seedVideo());
        $stream = fopen($disk->path($path), 'c+b');
        try {
            $this->assertTrue(ftruncate($stream, 264 * 1024 * 1024 + 1));
        } finally {
            fclose($stream);
        }
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['attachment_tokens' => [$token]])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments')->assertSee('264');
        $this->assertTrue($disk->exists($path));
        $this->assertDatabaseCount('lessons', 0);
    }

    public static function brokenVideos(): array
    {
        return [['header'], ['truncated'], ['text'], ['payload'], ['audio-only']];
    }

    #[DataProvider('brokenVideos')]
    public function test_invalid_video_cannot_be_ready_or_attached(string $case): void
    {
        $bytes = match ($case) {
            'header' => pack('N', 24).'ftypisom'.pack('N', 0).'isommp42',
            'truncated' => substr($this->seedVideo(), 0, -20),
            'payload' => substr_replace($this->seedVideo(), str_repeat("\0", strlen($this->seedVideo()) - strpos($this->seedVideo(), 'mdat') - 4), strpos($this->seedVideo(), 'mdat') + 4),
            'audio-only' => $this->fixtureBytes('audio-only.mp4'),
            default => 'not a video'
        };
        $id = $this->staged($bytes);
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'uploading']);
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => [$id]])->assertUnprocessable();
        $this->assertDatabaseCount('lesson_attachments', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_valid_video_gets_verified_receipt_and_valid_id_array_can_be_saved(): void
    {
        $id = $this->staged($this->seedVideo());
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertOk()->assertJsonPath('status', 'ready');
        $receipt = Storage::disk('local')->path('lesson-uploads/'.$id.'/.video-verified.json');
        $this->assertFileExists($receipt);
        $this->post(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => [$id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lesson_attachments', 1);
    }

    public function test_ready_sessions_created_before_fix_are_rechecked(): void
    {
        $id = $this->staged(pack('N', 24).'ftypisom'.pack('N', 0).'isommp42');
        DB::table('lesson_uploads')->where('id', $id)->update(['status' => 'ready', 'file_path' => 'lesson-uploads/'.$id.'/body.part', 'mime_type' => 'video/mp4', 'sha256' => hash_file('sha256', Storage::disk('local')->path('lesson-uploads/'.$id.'/body.part'))]);
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => [$id]])->assertUnprocessable();
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'uploading']);
        $this->assertDatabaseCount('lesson_attachments', 0);
    }

    public function test_missing_ffmpeg_fails_closed_without_ready(): void
    {
        $id = $this->staged($this->seedVideo());
        $binary = config('lesson_uploads.video.binary');
        config(['lesson_uploads.video.binary' => 'missing-lesson-ffmpeg-'.Str::uuid()]);
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertStatus(503)
            ->assertJsonPath('code', 'UPLOAD_VIDEO_SERVICE_UNAVAILABLE')->assertJsonPath('retryable', false)
            ->assertJsonPath('reason', 'executable_unavailable');
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'uploading']);
        $body = Storage::disk('local')->path('lesson-uploads/'.$id.'/body.part');
        $digest = hash_file('sha256', $body);
        config(['lesson_uploads.video.binary' => $binary]);
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('sha256', $digest);
        $this->assertSame($digest, hash_file('sha256', $body));
    }

    public static function historicalClaims(): array
    {
        return ['lesson_reference_present' => [false], 'lesson_reference_missing' => [true]];
    }

    #[DataProvider('historicalClaims')]
    public function test_historical_ready_state_cannot_reuse_or_prune_an_attached_video(bool $missingLessonReference): void
    {
        $id = $this->staged($this->seedVideo());
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertOk();
        $this->post(route('teacher.lessons.store'), $this->lessonData + ['upload_ids' => [$id]])->assertRedirect()->assertSessionHasNoErrors();
        $path = 'lesson-uploads/'.$id.'/body.part';
        // Simulate a completion from the old implementation overwriting a committed attachment state.
        DB::table('lesson_uploads')->where('id', $id)->update(array_merge(['status' => 'ready'], $missingLessonReference ? ['lesson_id' => null] : []));
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertConflict();
        $this->deleteJson(route('teacher.lessons.uploads.cancel', $id))->assertConflict();
        $payload = array_merge($this->lessonData, ['submission_key' => (string) Str::uuid(), 'upload_ids' => [$id]]);
        $this->postJson(route('teacher.lessons.store'), $payload)->assertConflict();
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('lesson_attachments', 1);
        DB::table('lesson_uploads')->where('id', $id)->update(['expires_at' => now()->subDay()]);
        app(LessonUploadService::class)->prune();
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'ready']);
    }

    public function test_media_process_timeout_fails_closed(): void
    {
        // Container inspection is trivial; Windows process startup itself exceeds this deadline.
        config(['lesson_uploads.video.timeout_seconds' => 0.001]);
        $id = $this->staged($this->seedVideo());
        Log::spy();
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'uploading']);
        Log::shouldHaveReceived('warning')->with('lesson_upload.media_rejected', ['reason' => 'timeout'])->once();
    }

    public function test_media_output_limit_fails_closed(): void
    {
        config(['lesson_uploads.video.max_output_bytes' => 1]);
        $id = $this->staged($this->seedVideo());
        $this->postJson(route('teacher.lessons.uploads.complete', $id))->assertUnprocessable();
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id, 'status' => 'uploading']);
    }

    public function test_receipt_is_bound_to_digest_and_verification_policy(): void
    {
        $id = $this->staged($this->seedVideo());
        $service = app(LessonUploadService::class);
        $state = $service->complete($id, $this->owner->id);
        $receipt = Storage::disk('local')->path('lesson-uploads/'.$id.'/.video-verified.json');
        $validator = app(LessonVideoValidator::class);
        $this->assertTrue($validator->hasReceipt($receipt, $state['sha256']));
        $this->assertFalse($validator->hasReceipt($receipt, str_repeat('0', 64)));
        config(['lesson_uploads.video.max_pixels' => 1024]);
        $this->assertFalse($validator->hasReceipt($receipt, $state['sha256']));
    }

    public function test_legacy_upload_and_direct_form_reject_header_only_video(): void
    {
        $bytes = pack('N', 24).'ftypisom'.pack('N', 0).'isommp42';
        $file = UploadedFile::fake()->createWithContent('broken.mp4', $bytes);
        $this->postJson(route('teacher.lessons.attachments.upload'), ['attachment' => $file])->assertUnprocessable();
        $this->postJson(route('teacher.lessons.store'), $this->lessonData + ['attachments' => [UploadedFile::fake()->createWithContent('broken.mp4', $bytes)]])->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('lesson_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('lesson-staging'));
    }

    public function test_legacy_valid_video_can_be_staged_saved_and_updated(): void
    {
        $file = UploadedFile::fake()->createWithContent('valid.mp4', $this->seedVideo());
        $staged = $this->postJson(route('teacher.lessons.attachments.upload'), ['attachment' => $file])->assertCreated()->json();
        $this->post(route('teacher.lessons.store'), $this->lessonData + ['attachment_tokens' => [$staged['token']], 'attachment_names' => ['valid.mp4']])->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::firstOrFail();
        $this->assertSame(1, $lesson->attachments()->count());
        $this->put(route('teacher.lessons.update', $lesson), array_merge($this->lessonData, ['title' => 'Updated video lesson', 'upload_ids' => null]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $lesson->attachments()->count());
        $this->get(route('teacher.lessons.attachments.download', [$lesson, $lesson->attachments->first()]))->assertOk();
    }

    public function test_missing_expired_directory_does_not_block_other_cleanup(): void
    {
        $service = app(LessonUploadService::class);
        $first = $this->staged('%PDF-1.4 first', 'first.pdf');
        $second = $this->staged('%PDF-1.4 second', 'second.pdf');
        DB::table('lesson_uploads')->update(['expires_at' => now()->subDay()]);
        Storage::disk('local')->deleteDirectory('lesson-uploads/'.$first);
        $this->assertSame(2, $service->prune());
        $this->assertDatabaseCount('lesson_uploads', 0);
        Storage::disk('local')->assertMissing('lesson-uploads/'.$second);
        $this->assertSame(0, $service->prune());
    }

    public function test_cleanup_logs_sanitized_failure_and_continues(): void
    {
        $first = $this->staged('%PDF-1.4 first', 'first.pdf');
        $second = $this->staged('%PDF-1.4 second', 'second.pdf');
        DB::table('lesson_uploads')->update(['expires_at' => now()->subDay()]);
        $service = \Mockery::mock(LessonUploadService::class)->makePartial();
        $service->shouldReceive('discard')->with($first, $this->owner->id)->once()->andThrow(new RuntimeException('sensitive-path-and-token'));
        $service->shouldReceive('discard')->with($second, $this->owner->id)->passthru();
        Log::spy();
        $this->assertSame(1, $service->prune());
        $this->assertDatabaseHas('lesson_uploads', ['id' => $first]);
        $this->assertDatabaseMissing('lesson_uploads', ['id' => $second]);
        Log::shouldHaveReceived('warning')->with('lesson_upload.cleanup_failed', \Mockery::on(fn ($context) => $context['upload_id'] === $first && ! str_contains(json_encode($context), 'sensitive-path-and-token')))->once();
    }

    public function test_existing_directory_cannot_be_reused_by_a_new_upload(): void
    {
        $id = (string) Str::uuid();
        $path = 'lesson-uploads/'.$id.'/body.part';
        Storage::disk('local')->put($path, 'permanent bytes');
        $this->postJson(route('teacher.lessons.uploads.start'), ['upload_id' => $id, 'name' => 'reuse.mp4', 'size' => 100])->assertConflict();
        $this->assertSame('permanent bytes', Storage::disk('local')->get($path));
    }

    public function test_down_refuses_populated_table_without_losing_rows(): void
    {
        $id = $this->staged('%PDF-1.4 active', 'active.pdf');
        $migration = require database_path('migrations/2026_10_24_000001_create_lesson_uploads_table.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('lesson_uploads'));
        $this->assertDatabaseHas('lesson_uploads', ['id' => $id]);
        Storage::disk('local')->assertExists('lesson-uploads/'.$id.'/body.part');
    }

    public function test_empty_table_can_be_rolled_back_and_recreated(): void
    {
        $migration = require database_path('migrations/2026_10_24_000001_create_lesson_uploads_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('lesson_uploads'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('lesson_uploads'));
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('users', 1);
    }
}
