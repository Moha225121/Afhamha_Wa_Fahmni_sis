<?php

use App\Http\Controllers\StudentPortal\AcademicController;
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
    Route::get('/assignments', [AcademicController::class, 'assignments'])->name('assignments.index');
    Route::get('/assignments/{assignment}', [AcademicController::class, 'assignment'])->name('assignments.show');
    Route::get('/assignments/{assignment}/attachment', [AcademicController::class, 'assignmentAttachment'])->middleware('throttle:60,1')->name('assignments.attachment');
    Route::get('/assignment-attachments/{attachment}/file', [AcademicController::class, 'attachmentFile'])->middleware('throttle:60,1')->name('assignments.attachments.file');
    Route::post('/assignments/{assignment}/submission', [AcademicController::class, 'submitAssignment'])->middleware('throttle:10,1')->name('assignments.submit');
    Route::get('/assignment-submissions/{submission}/file', [AcademicController::class, 'submissionFile'])->middleware('throttle:60,1')->name('assignments.submission-file');
    Route::get('/exams', [AcademicController::class, 'exams'])->name('exams.index');
    Route::post('/exams/{exam}/start', [AcademicController::class, 'startExam'])->middleware('throttle:10,1')->name('exams.start');
    Route::get('/exam-attempts/{attempt}', [AcademicController::class, 'attempt'])->name('exams.attempt');
    Route::post('/exam-attempts/{attempt}/answers/{question}', [AcademicController::class, 'saveAnswer'])->middleware('throttle:120,1')->name('exams.answers.save');
    Route::post('/exam-attempts/{attempt}/submit', [AcademicController::class, 'submitExam'])->middleware('throttle:10,1')->name('exams.submit');
    Route::get('/exam-attempts/{attempt}/result', [AcademicController::class, 'result'])->name('exams.result');
    Route::get('/attendance', [PortalController::class, 'attendance'])->name('attendance');
    Route::get('/schedule', [PortalController::class, 'schedule'])->name('schedule');
    Route::get('/notifications', [PortalController::class, 'notifications'])->name('notifications');
    Route::patch('/notifications/{notification}/read', [PortalController::class, 'markNotificationRead'])->middleware('throttle:60,1')->name('notifications.read');
});
