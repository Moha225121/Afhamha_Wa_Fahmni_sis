<?php

use App\Http\Controllers\GuardianPortal\ConversationController;
use App\Http\Controllers\GuardianPortal\FollowupController;
use App\Http\Controllers\GuardianPortal\NotificationController;
use App\Http\Controllers\GuardianPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('parent')->name('parent.')->middleware(['auth', 'parent'])->group(function (): void {
    Route::get('/students/{student}/analysis', \App\Http\Controllers\StudentLevelAnalysisController::class)->name('students.analysis');
    Route::post('/students/{student}/analysis', \App\Http\Controllers\StudentLevelAnalysisController::class)->middleware('throttle:6,1')->name('students.analysis.generate');
    Route::get('/finance', [\App\Http\Controllers\FinanceController::class, 'parent'])->name('finance');
    Route::get('/', fn () => redirect()->route('parent.dashboard'));
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/children', [PortalController::class, 'children'])->name('children.index');
    Route::get('/children/{student}', [PortalController::class, 'child'])->name('children.show');
    Route::get('/results', [PortalController::class, 'results'])->name('results');
    Route::get('/attendance', [PortalController::class, 'attendance'])->name('attendance');
    Route::get('/guardian-calls', [FollowupController::class, 'calls'])->name('guardian-calls');
    Route::get('/guardian-calls/{guardianCall}', [FollowupController::class, 'call'])->whereNumber('guardianCall')->name('guardian-calls.show');
    Route::get('/student-followup', [FollowupController::class, 'notes'])->name('student-followup');
    Route::get('/student-followup/{note}', [FollowupController::class, 'note'])->whereNumber('note')->name('student-followup.show');
    Route::get('/assignments', [PortalController::class, 'assignments'])->name('assignments');
    Route::get('/exams', [PortalController::class, 'exams'])->name('exams');
    Route::get('/messages', [PortalController::class, 'messages'])->name('messages');
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/create', [ConversationController::class, 'create'])->name('conversations.create');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{conversation}', [\App\Http\Controllers\SchoolChatController::class, 'show'])->name('conversations.show');
    Route::get('/conversations/{conversation}/messages', [\App\Http\Controllers\SchoolChatController::class, 'messages'])->middleware('throttle:120,1')->name('conversations.messages');
    Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\SchoolChatController::class, 'storeMessage'])->middleware('throttle:60,1')->name('conversations.messages.store');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/summary', [NotificationController::class, 'summary'])->middleware('throttle:60,1')->name('notifications.summary');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:60,1')->name('notifications.read-all');
    Route::match(['post', 'patch'], '/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->middleware('throttle:60,1')->name('notifications.read');
    Route::get('/announcements/{announcement}', [NotificationController::class, 'announcement'])->whereNumber('announcement')->name('announcements.show');
    Route::post('/push-subscriptions', [NotificationController::class, 'storeSubscription'])->name('push-subscriptions.store');
    Route::delete('/push-subscriptions/{subscription}', [NotificationController::class, 'destroySubscription'])->name('push-subscriptions.destroy');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    Route::get('/more', [PortalController::class, 'more'])->name('more');
});
