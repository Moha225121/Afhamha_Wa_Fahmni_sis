<?php

use App\Http\Controllers\GuardianPortal\ConversationController;
use App\Http\Controllers\GuardianPortal\NotificationController;
use App\Http\Controllers\GuardianPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('parent')->name('parent.')->middleware(['auth', 'parent'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('parent.dashboard'));
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/children', [PortalController::class, 'children'])->name('children.index');
    Route::get('/children/{student}', [PortalController::class, 'child'])->name('children.show');
    Route::get('/results', [PortalController::class, 'results'])->name('results');
    Route::get('/attendance', [PortalController::class, 'attendance'])->name('attendance');
    Route::get('/guardian-calls', [PortalController::class, 'guardianCalls'])->name('guardian-calls');
    Route::get('/student-followup', [PortalController::class, 'studentFollowup'])->name('student-followup');
    Route::get('/assignments', [PortalController::class, 'assignments'])->name('assignments');
    Route::get('/exams', [PortalController::class, 'exams'])->name('exams');
    Route::get('/messages', [PortalController::class, 'messages'])->name('messages');
    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/create', [ConversationController::class, 'create'])->name('conversations.create');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage'])->name('conversations.messages.store');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/push-subscriptions', [NotificationController::class, 'storeSubscription'])->name('push-subscriptions.store');
    Route::delete('/push-subscriptions/{subscription}', [NotificationController::class, 'destroySubscription'])->name('push-subscriptions.destroy');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    Route::get('/more', [PortalController::class, 'more'])->name('more');
});
