<?php

use App\Http\Controllers\Admin\AccountNumberingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FcmTokenController;
use App\Http\Controllers\FcmWorkerController;
use App\Http\Controllers\ParentPwaController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReceivedTeacherReportController;
use App\Http\Controllers\TeacherMessageController;
use App\Services\NotificationDestination;
use App\Support\SchoolBranding;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/school-branding/icon-{size}.png', fn (string $size) => response(SchoolBranding::iconPng((int) $size), 200, [
    'Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400',
]))->where('size', '180|192|512');
Route::get('/parent-app.webmanifest', [ParentPwaController::class, 'manifest'])->name('parent.pwa.manifest');
Route::get('/school-app-icon', [ParentPwaController::class, 'icon'])->name('school.pwa.icon');
Route::get('/parent-sw.js', fn () => Response::make(File::get(public_path('parent-sw.js')), 200, ['Content-Type' => 'application/javascript']))->name('parent.pwa.service-worker');
Route::get('/push/firebase-worker.js', FcmWorkerController::class)->name('fcm.worker');
Route::get('/student-manifest.webmanifest', fn () => Response::make(File::get(public_path('student-manifest.webmanifest')), 200, ['Content-Type' => 'application/manifest+json']))->name('student.pwa.manifest');
Route::view('/parent-offline.html', 'parent.offline')->name('parent.offline');
Route::get('/student-sw.js', fn () => Response::make(File::get(public_path('student-sw.js')), 200, ['Content-Type' => 'application/javascript']))->name('student.pwa.service-worker');
Route::view('/student-offline.html', 'student.offline')->name('student.offline');
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/teacher-messages/{notification}', [TeacherMessageController::class, 'show'])
    ->whereUuid('notification')->middleware('auth')->name('teacher-messages.show');
Route::post('/teacher-messages/{notification}/viewed', [TeacherMessageController::class, 'viewed'])
    ->whereUuid('notification')->middleware(['auth', 'throttle:60,1'])->name('teacher-messages.viewed');
// A signed, expiring capability acknowledges receipt only; it never exposes message content.
Route::post('/teacher-message-receipts/{notification}/delivered', [TeacherMessageController::class, 'delivered'])
    ->whereUuid('notification')->middleware(['signed:relative', 'throttle:60,1'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('teacher-message-receipts.delivered');
Route::middleware(['auth', 'no-cache'])->group(function (): void {
    Route::get('/received-reports', [ReceivedTeacherReportController::class, 'index'])->name('received-reports.index');
    Route::get('/received-reports/{delivery}', [ReceivedTeacherReportController::class, 'show'])->whereUuid('delivery')->name('received-reports.show');
    Route::get('/notifications', fn (Request $request, NotificationDestination $destinations) => redirect()->to($destinations->for($request->user(), [])))->name('notifications.index');
    Route::post('/fcm-tokens', [FcmTokenController::class, 'store'])->middleware('throttle:20,1')->name('fcm-tokens.store');
    Route::delete('/fcm-tokens', [FcmTokenController::class, 'destroy'])->middleware('throttle:20,1')->name('fcm-tokens.destroy');
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/push-subscriptions/{subscription}', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
});
Route::get('/student-card/{student}/verify', [AccountNumberingController::class, 'verify'])->middleware(['signed', 'throttle:30,1'])->name('student.card.verify');
require __DIR__.'/student.php';
require __DIR__.'/teacher.php';
require __DIR__.'/parent.php';
require __DIR__.'/supervisor.php';
require __DIR__.'/admin.php';
require __DIR__.'/library-uploads.php';
require __DIR__.'/finance.php';
require __DIR__.'/staff.php';
