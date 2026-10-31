<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AccountNumberingController;

Route::redirect('/', '/login');
Route::redirect('/favicon.ico', '/icons/nasbia-logo.jpg');
Route::get('/school-branding/icon-{size}.png', fn (string $size) => response(\App\Support\SchoolBranding::iconPng((int) $size), 200, [
    'Content-Type' => 'image/png',
    'Cache-Control' => 'public, max-age=86400',
]))->where('size', '180|192|512');
Route::get('/parent-manifest.webmanifest', fn () => Response::make(File::get(public_path('parent-manifest.webmanifest')), 200, ['Content-Type' => 'application/manifest+json']))->name('parent.pwa.manifest');
Route::get('/parent-sw.js', fn () => Response::make(File::get(public_path('parent-sw.js')), 200, ['Content-Type' => 'application/javascript']))->name('parent.pwa.service-worker');
Route::get('/student-manifest.webmanifest', fn () => Response::make(File::get(public_path('student-manifest.webmanifest')), 200, ['Content-Type' => 'application/manifest+json']))->name('student.pwa.manifest');
Route::view('/parent-offline.html', 'parent.offline')->name('parent.offline');
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/student-card/{student}/verify', [AccountNumberingController::class, 'verify'])->middleware('signed')->name('student.card.verify');
require __DIR__.'/student.php';
require __DIR__.'/teacher.php';
require __DIR__.'/parent.php';
require __DIR__.'/supervisor.php';
require __DIR__.'/admin.php';
require __DIR__.'/library-uploads.php';
require __DIR__.'/finance.php';
require __DIR__.'/staff.php';
