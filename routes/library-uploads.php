<?php

use App\Http\Controllers\Admin\LibraryUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/library/uploads')->name('admin.library.uploads.')->middleware(['auth', 'admin'])->group(function (): void {
    Route::post('/', [LibraryUploadController::class, 'start'])->middleware('throttle:30,1')->name('start');
    Route::post('/{upload}', [LibraryUploadController::class, 'chunk'])->whereUuid('upload')->name('chunk');
    Route::delete('/{upload}', [LibraryUploadController::class, 'cancel'])->whereUuid('upload')->name('cancel');
});
