<?php

namespace App\Console\Commands;

use App\Exceptions\LessonVideoUnavailableException;
use App\Services\LessonVideoValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CheckLessonVideo extends Command
{
    protected $signature = 'lessons:check-video {--runtime-only : Check executable and process availability only, without decoding or storage writes}';

    protected $description = 'Check configured video decoding and receipt storage without changing lessons or existing uploads';

    public function handle(LessonVideoValidator $validator): int
    {
        if ($this->option('runtime-only')) {
            return $this->checkRuntimeOnly($validator);
        }

        $directory = null;
        $root = null;
        $owned = false;
        $stage = 'runtime';
        $result = self::FAILURE;
        try {
            $validator->assertAvailable();
            $stage = 'signing_key';
            if (! is_string(config('app.key')) || config('app.key') === '') {
                throw new RuntimeException('Application signing key is unavailable.');
            }

            $stage = 'storage';
            $disk = Storage::disk('local');
            $uploadRoot = $disk->path('lesson-uploads');
            if (is_link($uploadRoot) || (! is_dir($uploadRoot) && ! $disk->makeDirectory('lesson-uploads'))) {
                throw new RuntimeException('Upload storage is unavailable.');
            }
            $root = realpath($uploadRoot);
            if (! $root || dirname($root) !== realpath($disk->path(''))) {
                throw new RuntimeException('Upload storage is not a direct local directory.');
            }
            $directory = $root.DIRECTORY_SEPARATOR.'.runtime-check-'.Str::uuid();
            if (! @mkdir($directory, 0700)) {
                throw new RuntimeException('Unable to create isolated runtime check directory.');
            }
            $owned = true;
            $stage = 'fixture';
            // A synthetic one-second, 128x96 H.264 blue frame sequence; no user media.
            $fixture = file_get_contents(resource_path('video/runtime-check.mp4'));
            if (! is_string($fixture) || $fixture === '') {
                throw new RuntimeException('Bundled runtime fixture is unavailable.');
            }
            $stage = 'storage';
            $file = $directory.'/body.part';
            $stream = fopen($file, 'xb');
            if ($stream === false) {
                throw new RuntimeException('Unable to create runtime check video.');
            }
            try {
                if (fwrite($stream, $fixture) !== strlen($fixture)) {
                    throw new RuntimeException('Unable to persist runtime check video.');
                }
            } finally {
                fclose($stream);
            }
            $stage = 'decode';
            $receipt = $directory.'/.video-verified.json';
            $digest = $validator->validate($file, $receipt);
            if (! hash_equals(hash('sha256', $fixture), $digest) || ! $validator->hasReceipt($receipt, $digest)) {
                throw new RuntimeException('Runtime verification receipt was not accepted.');
            }
            $result = self::SUCCESS;
        } catch (LessonVideoUnavailableException $exception) {
            $this->runtimeFailure($exception);
        } catch (ValidationException) {
            $this->error('Video runtime check failed [decode]. The configured FFmpeg could not fully decode the bundled MP4 under the upload policy; check its version, decoders and processing limits.');
        } catch (Throwable) {
            $action = match ($stage) {
                'signing_key' => 'Restore the existing application signing key and configuration; do not rotate it for this check.',
                'fixture' => 'Restore resources/video/runtime-check.mp4 from the reviewed release.',
                'storage' => 'Check free space, directory safety and write permissions in local lesson upload storage.',
                default => 'Check the configured FFmpeg runtime and local receipt storage.',
            };
            $this->error('Video runtime check failed ['.$stage.']. '.$action);
        } finally {
            if ($owned && ! $this->cleanOwnedFiles($directory, $root)) {
                $this->error('Video runtime check cleanup failed. Existing data was preserved; inspect only the .runtime-check-* directory created by this run.');
                $result = self::FAILURE;
            }
        }

        if ($result === self::SUCCESS) {
            $this->info('Video runtime check passed: configured FFmpeg decoded the MP4 and persisted a valid receipt; temporary check files removed.');
            $this->line('This verifies the invoking PHP identity and environment. PHP-FPM must use the same executable, process permissions and storage access.');
        }

        return $result;
    }

    private function checkRuntimeOnly(LessonVideoValidator $validator): int
    {
        $this->line('Availability only: decoding, receipt storage and PHP-FPM access are not checked.');
        try {
            $validator->assertAvailable();
            $this->info('Video runtime availability check passed: configured executable and PHP process function are available.');

            return self::SUCCESS;
        } catch (LessonVideoUnavailableException $exception) {
            $this->runtimeFailure($exception);

            return $exception->reason === 'executable_unavailable' ? 78 : self::FAILURE;
        } catch (Throwable) {
            $this->error('Video runtime check failed [runtime]. Check the configured executable and PHP process permissions.');

            return self::FAILURE;
        }
    }

    private function runtimeFailure(LessonVideoUnavailableException $exception): void
    {
        $action = match ($exception->reason) {
            'executable_unavailable' => 'Install FFmpeg and set LESSON_FFMPEG_BINARY to its executable absolute path; refresh the application configuration cache.',
            'process_unavailable' => 'Enable PHP proc_open for the application runtime.',
            'process_failed' => 'Check that the application runtime can launch the configured FFmpeg process.',
            'receipt_unavailable' => 'Check free space and write permissions in local lesson upload storage for the application runtime.',
        };
        $this->error('Video runtime check failed ['.$exception->reason.']. '.$action);
    }

    private function cleanOwnedFiles(string $directory, string $root): bool
    {
        if (is_link($root) || is_link($directory) || realpath($root) !== $root || realpath($directory) !== $directory || dirname($directory) !== $root) {
            return false;
        }
        $clean = true;
        foreach (['body.part', '.video-verified.json'] as $name) {
            $path = $directory.'/'.$name;
            if ((file_exists($path) || is_link($path)) && ! @unlink($path)) {
                $clean = false;
            }
        }

        // Never recursively remove a directory or delete unexpected files.
        return @rmdir($directory) && $clean;
    }
}
