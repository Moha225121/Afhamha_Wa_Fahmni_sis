<?php

use App\Http\Controllers\PortalAnnouncementController;
use App\Http\Controllers\StaffNotificationController;
use App\Http\Controllers\StaffPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('staff')->name('staff.')->middleware(['auth', 'staff', 'no-cache'])->group(function () {
    Route::redirect('/', '/staff/dashboard');
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/ads', [PortalAnnouncementController::class, 'index'])->name('ads.index');
    Route::get('/students/create', [PortalController::class, 'studentCreate'])->middleware('permission:students.create')->name('students.create');
    Route::post('/students', [PortalController::class, 'studentStore'])->middleware('permission:students.create')->name('students.store');
    Route::get('/students/{student}/edit', [PortalController::class, 'studentEdit'])->middleware('permission:students.update')->name('students.edit');
    Route::put('/students/{student}', [PortalController::class, 'studentUpdate'])->middleware('permission:students.update')->name('students.update');
    Route::get('/students/{student}/guardians', [PortalController::class, 'guardians'])->middleware('permission:student-guardians.manage')->name('students.guardians');
    Route::put('/students/{student}/guardians', [PortalController::class, 'guardiansUpdate'])->middleware('permission:student-guardians.manage')->name('students.guardians.update');
    Route::get('/notifications', [StaffNotificationController::class, 'create'])->middleware('permission:parent-notifications.send')->name('notifications');
    Route::post('/notifications', [StaffNotificationController::class, 'store'])->middleware('permission:parent-notifications.send')->name('notifications.store');
    Route::post('/calls', [PortalController::class, 'callStore'])->middleware('permission:guardian-calls.manage')->name('calls.store');
    Route::patch('/calls/{guardianCall}', [PortalController::class, 'callUpdate'])->middleware('permission:guardian-calls.manage')->name('calls.update');
    Route::post('/announcements', [PortalController::class, 'announcementStore'])->middleware('permission:announcements.create')->name('announcements.store');
    Route::patch('/announcements/{announcement}/publish', [PortalController::class, 'announcementPublish'])->middleware('permission:announcements.publish')->name('announcements.publish');
    Route::post('/announcements/{announcement}/broadcast', [PortalController::class, 'announcementBroadcast'])->middleware('permission:announcements.broadcast')->name('announcements.broadcast');
    Route::post('/activities', [PortalController::class, 'activityStore'])->middleware('permission:activities.manage')->name('activities.store');
    Route::patch('/activities/{activity}', [PortalController::class, 'activityUpdate'])->middleware('permission:activities.manage')->name('activities.update');
    Route::get('/{module}', [PortalController::class, 'module'])->whereIn('module', ['students', 'academic', 'attendance', 'assignments', 'teachers', 'calls', 'announcements', 'activities'])->name('module');
});
