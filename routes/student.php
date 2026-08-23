<?php

use App\Http\Controllers\StudentPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('student')->name('student.')->middleware(['auth', 'student'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('student.dashboard'));
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/results', [PortalController::class, 'results'])->name('results');
    Route::get('/messages', [PortalController::class, 'messages'])->name('messages');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
});
