<?php

use App\Http\Controllers\TeacherPortal\AssignmentSubmissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('teacher')->name('teacher.')->middleware(['auth', 'teacher'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('teacher.assignments.index'));
    Route::get('/assignments', [AssignmentSubmissionController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/{assignment}', [AssignmentSubmissionController::class, 'show'])->name('assignments.show');
    Route::get('/submissions/{submission}/file', [AssignmentSubmissionController::class, 'download'])
        ->middleware('throttle:60,1')
        ->name('submissions.file');
});
