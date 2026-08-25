<?php

use App\Http\Controllers\StudentPortal\EducationController;
use App\Http\Controllers\StudentPortal\PortalController;
use App\Http\Controllers\StudentPortal\TutorController;
use Illuminate\Support\Facades\Route;

Route::prefix('student')->name('student.')->middleware(['auth', 'student'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('student.dashboard'));
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/subjects', [EducationController::class, 'subjects'])->name('subjects.index');
    Route::get('/subjects/{subject}/lessons', [EducationController::class, 'lessons'])->whereNumber('subject')->name('lessons.index');
    Route::get('/subjects/{subject}/lessons/{lesson}', [EducationController::class, 'lesson'])->whereNumber(['subject', 'lesson'])->name('lessons.show');
    Route::get('/subjects/{subject}', [EducationController::class, 'subject'])->whereNumber('subject')->name('subjects.show');
    Route::get('/library', [EducationController::class, 'library'])->name('library.index');
    Route::get('/library/{resource}/download', [EducationController::class, 'downloadResource'])->whereNumber('resource')->name('library.download');
    Route::get('/subjects/{subject}/lessons/{lesson}/attachments/{attachment}', [EducationController::class, 'downloadAttachment'])->whereNumber(['subject', 'lesson', 'attachment'])->name('lessons.attachments.download');
    Route::get('/tutor', [TutorController::class, 'index'])->name('tutor.index');
    Route::post('/tutor/conversations', [TutorController::class, 'storeConversation'])->middleware('throttle:smart-tutor-conversations')->name('tutor.conversations.store');
    Route::get('/tutor/conversations/{conversation}', [TutorController::class, 'show'])->whereNumber('conversation')->name('tutor.show');
    Route::post('/tutor/conversations/{conversation}/messages', [TutorController::class, 'storeMessage'])->whereNumber('conversation')->middleware('throttle:smart-tutor-messages')->name('tutor.messages.store');
    Route::get('/results', [PortalController::class, 'results'])->name('results');
    Route::get('/messages', [PortalController::class, 'messages'])->name('messages');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
});
