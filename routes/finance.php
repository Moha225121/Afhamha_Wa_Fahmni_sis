<?php

use App\Http\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->name('finance.')->middleware('auth')->group(function (): void {
    Route::get('/', [FinanceController::class, 'index'])->name('index');
    Route::get('/students/{student}', [FinanceController::class, 'show'])->name('students.show');
    Route::post('/students/{student}/payments', [FinanceController::class, 'pay'])->name('students.pay');
    Route::patch('/students/{student}/payments/{payment}/void', [FinanceController::class, 'void'])->name('students.void');
    Route::post('/students/{student}/discounts', [FinanceController::class, 'discount'])->name('students.discount');
    Route::get('/receipts/{receipt}', [FinanceController::class, 'receipt'])->name('receipts.show');
    Route::get('/fees', [FinanceController::class, 'templates'])->name('templates');
    Route::post('/fees', [FinanceController::class, 'templateStore'])->name('templates.store');
    Route::post('/fees/{template}/apply', [FinanceController::class, 'apply'])->name('templates.apply');
    Route::patch('/fees/{template}', [FinanceController::class, 'templateToggle'])->name('templates.toggle');
    Route::post('/officers', [FinanceController::class, 'officerStore'])->name('officers.store');
});
