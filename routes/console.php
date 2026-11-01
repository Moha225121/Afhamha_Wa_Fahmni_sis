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

Artisan::command('announcements:notify-due', function (\App\Services\StudentNotificationService $students, \App\Services\ParentNotificationService $parents): void {
    $through = now();
    $cursor = \Illuminate\Support\Facades\DB::table('fcm_schedule_cursors')->where('name', 'announcements')->value('checked_through');
    if ($cursor === null) {
        throw new \RuntimeException('The announcement notification cursor is missing. Run the FCM migration.');
    }
    \App\Models\Announcement::currentlyPublished()
        ->whereNotNull('published_at')
        ->where('published_at', '>=', $cursor)
        ->where('published_at', '<=', $through)
        ->orderBy('id')->chunkById(100, function ($announcements) use ($students, $parents): void {
            foreach ($announcements as $announcement) {
                $students->sendAnnouncement($announcement);
                $parents->sendAnnouncement($announcement);
            }
        });
    // Advance only after the complete window succeeds; notification IDs deduplicate retries.
    \Illuminate\Support\Facades\DB::table('fcm_schedule_cursors')->where('name', 'announcements')->update(['checked_through' => $through]);
})->purpose('Notify school portals when scheduled announcements become visible');
Schedule::command('announcements:notify-due')->everyMinute()->withoutOverlapping();
