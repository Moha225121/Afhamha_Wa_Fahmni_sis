<?php

use App\Http\Controllers\LibraryCatalogController;
use App\Http\Controllers\PortalAnnouncementController;
use App\Http\Controllers\PortalMessengerController;
use App\Http\Controllers\SchoolChatController;
use App\Http\Controllers\StudentLevelAnalysisController;
use App\Http\Controllers\TeacherPortal\AssignmentController;
use App\Http\Controllers\TeacherPortal\AssignmentSubmissionController;
use App\Http\Controllers\TeacherPortal\ClassroomController;
use App\Http\Controllers\TeacherPortal\DashboardController;
use App\Http\Controllers\TeacherPortal\ExamController;
use App\Http\Controllers\TeacherPortal\GradeController;
use App\Http\Controllers\TeacherPortal\LessonController;
use App\Http\Controllers\TeacherPortal\LessonUploadController;
use App\Http\Controllers\TeacherPortal\NotificationController;
use App\Http\Controllers\TeacherPortal\NotificationRecoveryController;
use App\Http\Controllers\TeacherPortal\ProfileController;
use App\Http\Controllers\TeacherPortal\ReportController;
use App\Http\Controllers\TeacherPortal\StudentController;
use App\Http\Controllers\TeacherPortal\TeacherReportDeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('teacher')->name('teacher.')->middleware(['auth', 'teacher', 'no-cache'])->group(function (): void {
    Route::get('/conversations', [SchoolChatController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/{conversation}', [SchoolChatController::class, 'show'])->name('conversations.show');
    Route::get('/conversations/{conversation}/messages', [SchoolChatController::class, 'messages'])->middleware('throttle:120,1')->name('conversations.messages');
    Route::post('/conversations/{conversation}/messages', [SchoolChatController::class, 'storeMessage'])->middleware('throttle:60,1')->name('conversations.messages.store');
    Route::get('/students/{student}/analysis', StudentLevelAnalysisController::class)->name('students.analysis');
    Route::post('/students/{student}/analysis', StudentLevelAnalysisController::class)->middleware('throttle:6,1')->name('students.analysis.generate');
    Route::get('/', fn () => redirect()->route('teacher.dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/index', fn () => redirect()->route('teacher.dashboard'))->name('dashboard.index');
    Route::get('/ads', [PortalAnnouncementController::class, 'index'])->name('ads.index');
    Route::get('/messages', [PortalMessengerController::class, 'index'])->name('messages');
    Route::post('/messages', [PortalMessengerController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
    Route::post('/messages/read', [PortalMessengerController::class, 'read'])->middleware('throttle:60,1')->name('messages.read');
    Route::get('/notifications', [NotificationController::class, 'create'])->name('notifications.index');
    Route::post('/notifications/preview', [NotificationController::class, 'preview'])->middleware('throttle:teacher-notification-preview')->name('notifications.preview');
    Route::post('/notifications', [NotificationController::class, 'store'])->middleware('throttle:teacher-notifications')->name('notifications.store');
    Route::get('/notifications/sent', [NotificationController::class, 'sent'])->name('notifications.sent');
    Route::post('/notifications/{dispatch}/recover', NotificationRecoveryController::class)->whereUuid('dispatch')->middleware('throttle:12,1')->name('notifications.recover');
    Route::get('/notifications/{dispatch}', [NotificationController::class, 'show'])->whereUuid('dispatch')->name('notifications.show');
    Route::get('/library', [LibraryCatalogController::class, 'index'])->name('library.index');
    Route::get('/library/{resource}/read', [LibraryCatalogController::class, 'read'])->whereNumber('resource')->name('library.read');
    Route::get('/library/{resource}/file', [LibraryCatalogController::class, 'file'])->whereNumber('resource')->name('library.file');
    Route::get('/library/{resource}/download', [LibraryCatalogController::class, 'download'])->whereNumber('resource')->name('library.download');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/{student}', [StudentController::class, 'show'])->name('students.show');
    Route::post('/students/{student}/notes', [StudentController::class, 'noteStore'])->name('students.notes.store');

    Route::get('/classes', [ClassroomController::class, 'index'])->name('classes.index');
    Route::get('/classes/{classroom}', [ClassroomController::class, 'show'])->name('classes.show');

    Route::get('/lessons', [LessonController::class, 'index'])->name('lessons.index');
    Route::get('/lessons/create', [LessonController::class, 'create'])->name('lessons.create');
    Route::post('/lessons', [LessonController::class, 'store'])->name('lessons.store');
    Route::prefix('lessons/uploads')->name('lessons.uploads.')->middleware(['permission:lessons.manage', 'throttle:600,1,lesson-uploads'])->group(function (): void {
        foreach (['start' => ['post', ''], 'status' => ['get', '/{upload}'], 'chunk' => ['post', '/{upload}/chunks'], 'complete' => ['post', '/{upload}/complete'], 'cancel' => ['delete', '/{upload}']] as $stage => [$method, $path]) {
            Route::$method($path, ['uses' => LessonUploadController::class.'@handle', 'upload_stage' => $stage])
                ->whereUuid('upload')->name($stage);
        }
    });
    Route::post('/lessons/attachments/upload', [LessonController::class, 'uploadAttachment'])->middleware(['permission:lessons.manage', 'throttle:30,1'])->name('lessons.attachments.upload');
    Route::delete('/lessons/attachments/{token}', [LessonController::class, 'discardAttachment'])->middleware('permission:lessons.manage')->whereUuid('token')->name('lessons.attachments.discard');
    Route::get('/lessons/{lesson}', [LessonController::class, 'show'])->whereNumber('lesson')->name('lessons.show');
    Route::get('/lessons/{lesson}/attachments/{attachment}', [LessonController::class, 'attachment'])->whereNumber(['lesson', 'attachment'])->name('lessons.attachments.download');
    Route::get('/lessons/{lesson}/views', [LessonController::class, 'views'])->whereNumber('lesson')->name('lessons.views');
    Route::get('/lessons/{lesson}/edit', [LessonController::class, 'edit'])->name('lessons.edit');
    Route::put('/lessons/{lesson}', [LessonController::class, 'update'])->name('lessons.update');
    Route::patch('/lessons/{lesson}/publish', [LessonController::class, 'publish'])->name('lessons.publish');
    Route::patch('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->name('lessons.cancel');
    Route::delete('/lessons/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');

    Route::get('/exams', [ExamController::class, 'index'])->name('exams.index');
    Route::get('/exams/create', [ExamController::class, 'create'])->name('exams.create');
    Route::post('/exams', [ExamController::class, 'store'])->name('exams.store');
    Route::get('/exams/{exam}/edit', [ExamController::class, 'edit'])->name('exams.edit');
    Route::put('/exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
    Route::delete('/exams/{exam}', [ExamController::class, 'destroy'])->name('exams.destroy');
    Route::patch('/exams/{exam}/status', [ExamController::class, 'status'])->name('exams.status');
    Route::patch('/exams/{exam}/cancel', [ExamController::class, 'cancel'])->name('exams.cancel');

    Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/create', [AssignmentController::class, 'create'])->name('assignments.create');
    Route::post('/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/assignments/{assignment}/edit', [AssignmentController::class, 'edit'])->name('assignments.edit');
    Route::get('/assignments/{assignment}', [AssignmentSubmissionController::class, 'show'])->name('assignments.show');
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
    Route::patch('/assignments/{assignment}/cancel', [AssignmentController::class, 'cancel'])->name('assignments.cancel');
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
    Route::get('/assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])->name('assignments.submissions');
    Route::post('/assignments/{assignment}/submissions', [AssignmentController::class, 'submissionsStore'])->name('assignments.submissions.store');
    Route::get('/submissions/{submission}/file', [AssignmentSubmissionController::class, 'download'])->middleware('throttle:60,1')->name('submissions.file');

    Route::get('/grades', [GradeController::class, 'index'])->name('grades.index');
    Route::post('/grades', [GradeController::class, 'store'])->name('grades.store');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/create', [ReportController::class, 'create'])->name('reports.create');
    Route::post('/reports/preview', [ReportController::class, 'preview'])->name('reports.preview');
    Route::post('/reports/sources', [ReportController::class, 'sources'])->name('reports.sources');
    Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('/reports/{report}/recipients', [TeacherReportDeliveryController::class, 'recipients'])->where('report', '[1-9][0-9]{0,17}')->name('reports.recipients');
    Route::post('/reports/{report}/send-preview', [TeacherReportDeliveryController::class, 'preview'])->where('report', '[1-9][0-9]{0,17}')->middleware('throttle:60,1')->name('reports.send-preview');
    Route::post('/reports/{report}/send', [TeacherReportDeliveryController::class, 'send'])->where('report', '[1-9][0-9]{0,17}')->middleware('throttle:10,1')->name('reports.send');
    Route::get('/reports/{report}/print', [ReportController::class, 'print'])->where('report', '[1-9][0-9]{0,17}')->name('reports.print');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', '[1-9][0-9]{0,17}')->name('reports.show');
    Route::put('/reports/{report}', [ReportController::class, 'update'])->where('report', '[1-9][0-9]{0,17}')->name('reports.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
