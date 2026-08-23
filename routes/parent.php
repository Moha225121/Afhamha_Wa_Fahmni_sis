<?php

use App\Http\Controllers\GuardianPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::prefix('parent')->name('parent.')->middleware(['auth', 'parent'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('parent.dashboard'));
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/children', [PortalController::class, 'children'])->name('children.index');
    Route::get('/children/{student}', [PortalController::class, 'child'])->name('children.show');
    Route::get('/results', [PortalController::class, 'results'])->name('results');
    Route::get('/messages', [PortalController::class, 'messages'])->name('messages');
    Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
    Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    Route::get('/more', [PortalController::class, 'more'])->name('more');
});
