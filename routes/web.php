<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/parent-manifest.webmanifest', fn () => Response::make(File::get(public_path('parent-manifest.webmanifest')), 200, ['Content-Type' => 'application/manifest+json']))->name('parent.pwa.manifest');
Route::get('/parent-sw.js', fn () => Response::make(File::get(public_path('parent-sw.js')), 200, ['Content-Type' => 'application/javascript']))->name('parent.pwa.service-worker');
Route::view('/parent-offline.html', 'parent.offline')->name('parent.offline');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');
require __DIR__.'/student.php';
require __DIR__.'/teacher.php';
require __DIR__.'/parent.php';
require __DIR__.'/admin.php';
