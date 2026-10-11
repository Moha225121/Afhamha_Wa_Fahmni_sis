<?php

use App\Models\LibraryResource;
use App\Services\ExamAttemptService;
use App\Services\LibraryUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('library:verify-files {--fail-on-error : Stop on the first missing library file}', function (): void {
    $failOnError = $this->option('fail-on-error');
    $checked = 0;
    $restored = 0;
    $errors = [];

    foreach (LibraryResource::query()->orderBy('id')->cursor() as $resource) {
        $checked++;
        $disk = in_array($resource->disk, ['local', 'public'], true) ? $resource->disk : 'public';
        $path = str_replace('\\', '/', (string) $resource->file_path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, "\0") || preg_match('~(^|/)\.{1,2}(/|$)~', $path)) {
            $message = 'Rejected library file path for resource #'.$resource->id.': '.$path;
            $errors[] = $message;
            if ($failOnError) {
                throw new RuntimeException($message);
            }
            continue;
        }

        $targetDisk = Storage::disk($disk);
        if ($targetDisk->exists($path)) {
            continue;
        }

        $sourceDiskName = collect(['local', 'public'])->reject(fn (string $candidate) => $candidate === $disk)->first();
        $sourceDisk = $sourceDiskName ? Storage::disk($sourceDiskName) : null;

        if (! $sourceDisk || ! $sourceDisk->exists($path)) {
            $message = 'Missing library file for resource #'.$resource->id.' on '.$disk.': '.$path;
            $errors[] = $message;
            if ($failOnError) {
                throw new RuntimeException($message);
            }
            continue;
        }

        $stream = $sourceDisk->readStream($path);
        if ($stream === false) {
            $message = 'Could not read missing library file for resource #'.$resource->id.': '.$path;
            $errors[] = $message;
            if ($failOnError) {
                throw new RuntimeException($message);
            }
            continue;
        }

        try {
            $targetDisk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $targetDisk->exists($path)) {
            $message = 'Failed to restore library file for resource #'.$resource->id.' to '.$disk.': '.$path;
            $errors[] = $message;
            if ($failOnError) {
                throw new RuntimeException($message);
            }
            continue;
        }

        $resource->update(['disk' => $disk]);
        $restored++;
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->warn($error);
        }
        $this->error('Verified '.$checked.' library resource(s), restored '.$restored.' missing file(s), and found '.count($errors).' unresolved issue(s).');
        if ($failOnError) {
            throw new RuntimeException('Library verification failed.');
        }

        return;
    }

    $this->info('Verified '.$checked.' library resource(s) and restored '.$restored.' missing file(s).');
})->purpose('Verify library resource files and restore missing PDFs from the alternate disk');

Artisan::command('student-exams:finalize-expired', function (ExamAttemptService $service): void {
    $this->info($service->finalizeExpired().' expired attempt(s) finalized.');
})->purpose('Finalize and grade expired student exam attempts');

Schedule::command('student-exams:finalize-expired')->everyMinute()->withoutOverlapping(10);

Artisan::command('library:prune-uploads', function (LibraryUploadService $uploads): void {
    $this->info($uploads->pruneExpired().' expired library upload(s) removed.');
})->purpose('Remove expired, unlocked temporary library uploads');

Schedule::command('library:prune-uploads')->daily()->withoutOverlapping(60);

Artisan::command('finance:send-reminders', function (\App\Services\StudentFinanceService $finance): void {
    $this->info($finance->remind().' installment reminders recorded.');
})->purpose('Notify guardians about upcoming and overdue installments once per stage');
Schedule::command('finance:send-reminders')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('results:publish-due', function (\App\Services\ResultPublicationService $results): void {
    $ids = \Illuminate\Support\Facades\DB::table('exam_periods')->where('status', 'approved')->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->pluck('id');
    foreach ($ids as $id) {
        try { $results->transition($id, 'published'); }
        catch (\Illuminate\Validation\ValidationException $e) { report($e); $this->warn('Period '.$id.' requires review.'); }
    }
    $this->info('Scheduled result publications processed.');
})->purpose('Publish approved result periods when their release date arrives');
Schedule::command('results:publish-due')->everyMinute()->withoutOverlapping();
