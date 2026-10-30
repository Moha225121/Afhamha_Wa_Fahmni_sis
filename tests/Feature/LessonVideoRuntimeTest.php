<?php

namespace Tests\Feature;

use App\Exceptions\LessonVideoUnavailableException;
use App\Services\LessonVideoValidator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LessonVideoRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function realRuntime(): LessonVideoValidator
    {
        if ($binary = getenv('LESSON_TEST_FFMPEG')) {
            config(['lesson_uploads.video.binary' => $binary]);
        }
        $validator = app(LessonVideoValidator::class);
        try {
            $validator->assertAvailable();
        } catch (LessonVideoUnavailableException) {
            $this->markTestSkipped('Set LESSON_TEST_FFMPEG to run actual FFmpeg runtime checks.');
        }

        return $validator;
    }

    public function test_command_decodes_fixture_and_writes_receipt_without_changing_existing_data(): void
    {
        $this->realRuntime();
        $disk = Storage::disk('local');
        $disk->put('lesson-uploads/existing/body.part', 'existing upload');
        $disk->put('lesson-uploads/.runtime-check-existing/backup.mp4', 'existing backup');
        $before = $disk->allFiles();

        foreach ([1, 2] as $attempt) {
            $this->artisan('lessons:check-video')
                ->expectsOutputToContain('Video runtime check passed:')
                ->assertSuccessful();
            $this->assertSame($before, $disk->allFiles());
            $this->assertSame('existing upload', $disk->get('lesson-uploads/existing/body.part'));
            $this->assertSame('existing backup', $disk->get('lesson-uploads/.runtime-check-existing/backup.mp4'));
            $this->assertCount(2, $disk->directories('lesson-uploads'));
        }
    }

    public function test_missing_executable_returns_actionable_nonzero_without_touching_storage(): void
    {
        config(['lesson_uploads.video.binary' => 'missing-lesson-runtime-executable']);
        Storage::disk('local')->put('lesson-uploads/keep/backup.mp4', 'preserved');

        $this->artisan('lessons:check-video')
            ->expectsOutputToContain('[executable_unavailable]')
            ->assertFailed();
        $this->assertSame(['lesson-uploads/keep/backup.mp4'], Storage::disk('local')->allFiles());
        $this->assertSame('preserved', Storage::disk('local')->get('lesson-uploads/keep/backup.mp4'));
    }

    public function test_runtime_only_checks_availability_without_storage_or_decode(): void
    {
        config(['lesson_uploads.video.binary' => PHP_BINARY, 'app.key' => '']);
        Storage::shouldReceive('disk')->never();

        $this->artisan('lessons:check-video', ['--runtime-only' => true])
            ->expectsOutputToContain('Availability only:')
            ->expectsOutputToContain('Video runtime availability check passed:')
            ->assertSuccessful();
    }

    public function test_runtime_only_distinguishes_missing_executable_with_exit_78(): void
    {
        config(['lesson_uploads.video.binary' => 'missing-lesson-runtime-executable']);
        Storage::shouldReceive('disk')->never();

        $this->artisan('lessons:check-video', ['--runtime-only' => true])
            ->expectsOutputToContain('[executable_unavailable]')
            ->assertExitCode(78);
    }

    public function test_runtime_only_process_unavailable_returns_1_without_storage_access(): void
    {
        $this->mock(LessonVideoValidator::class)->shouldReceive('assertAvailable')->once()
            ->andThrow(new LessonVideoUnavailableException('process_unavailable'));
        Storage::shouldReceive('disk')->never();

        $this->artisan('lessons:check-video', ['--runtime-only' => true])
            ->expectsOutputToContain('[process_unavailable]')
            ->assertExitCode(1);
    }

    public function test_failed_decode_cleans_only_owned_check_files(): void
    {
        $this->realRuntime();
        config(['lesson_uploads.video.max_pixels' => 1]);
        Storage::disk('local')->put('lesson-uploads/keep/body.part', 'preserved');

        $this->artisan('lessons:check-video')->expectsOutputToContain('[decode]')->assertFailed();
        $this->assertSame(['lesson-uploads/keep/body.part'], Storage::disk('local')->allFiles());
        $this->assertSame(['lesson-uploads/keep'], Storage::disk('local')->directories('lesson-uploads'));
    }

    public function test_missing_signing_key_does_not_create_check_files(): void
    {
        config(['lesson_uploads.video.binary' => PHP_BINARY, 'app.key' => '']);

        $this->artisan('lessons:check-video')->expectsOutputToContain('[signing_key]')->assertFailed();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('local')->allDirectories());
    }

    public function test_blocked_upload_root_preserves_existing_file(): void
    {
        config(['lesson_uploads.video.binary' => PHP_BINARY]);
        Storage::disk('local')->put('lesson-uploads', 'preserved');

        $this->artisan('lessons:check-video')->expectsOutputToContain('[storage]')->assertFailed();
        $this->assertSame('preserved', Storage::disk('local')->get('lesson-uploads'));
    }

    public function test_receipt_write_error_is_a_typed_service_failure_and_preserves_target(): void
    {
        $validator = $this->realRuntime();
        Storage::disk('local')->put('receipt-directory/backup.mp4', 'preserved');
        try {
            $validator->validate(resource_path('video/runtime-check.mp4'), Storage::disk('local')->path('receipt-directory'));
            $this->fail('An unwritable receipt target must not pass validation.');
        } catch (LessonVideoUnavailableException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('receipt_unavailable', $exception->reason);
        }
        $this->assertSame('preserved', Storage::disk('local')->get('receipt-directory/backup.mp4'));
    }

    public function test_cleanup_preserves_unexpected_files_and_returns_failure(): void
    {
        $validator = $this->mock(LessonVideoValidator::class);
        $validator->shouldReceive('assertAvailable')->once()->andReturn(PHP_BINARY);
        $validator->shouldReceive('validate')->once()->andReturnUsing(function (string $file): string {
            file_put_contents(dirname($file).'/unexpected.backup', 'preserved');

            return hash_file('sha256', $file);
        });
        $validator->shouldReceive('hasReceipt')->once()->andReturnTrue();

        $this->artisan('lessons:check-video')->expectsOutputToContain('cleanup failed')->assertFailed();
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/unexpected.backup', $files[0]);
        $this->assertSame('preserved', Storage::disk('local')->get($files[0]));
    }

    public function test_disabled_proc_open_returns_typed_503_instead_of_constructor_500(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    app(App\Services\LessonVideoValidator::class)->validate(resource_path('video/runtime-check.mp4'));
    exit(2);
} catch (App\Exceptions\LessonVideoUnavailableException $exception) {
    echo $exception->reason.':'.$exception->getStatusCode();
}
PHP;
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code], base_path(), null, null, 30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('process_unavailable:503', $process->getOutput());
    }

    public function test_process_constructor_failure_is_caught_even_after_availability_check(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$validator = new class extends App\Services\LessonVideoValidator {
    public function assertAvailable(): string { return PHP_BINARY; }
};
try {
    $validator->validate(resource_path('video/runtime-check.mp4'));
    exit(2);
} catch (App\Exceptions\LessonVideoUnavailableException $exception) {
    echo $exception->reason.':'.$exception->getStatusCode();
}
PHP;
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $code], base_path(), null, null, 30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('process_failed:503', $process->getOutput());
    }
}
