<?php

use App\Services\ExamAttemptService;
use App\Services\LibraryUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('student-exams:finalize-expired', function (ExamAttemptService $service): void {
    $this->info($service->finalizeExpired().' expired attempt(s) finalized.');
})->purpose('Finalize and grade expired student exam attempts');

Schedule::command('student-exams:finalize-expired')->everyMinute()->withoutOverlapping(10);

Artisan::command('library:prune-uploads', function (LibraryUploadService $uploads): void {
    $this->info($uploads->pruneExpired().' expired library upload(s) removed.');
})->purpose('Remove expired, unlocked temporary library uploads');

Schedule::command('library:prune-uploads')->daily()->withoutOverlapping(60);
