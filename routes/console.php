<?php

use App\Services\ExamAttemptService;
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
