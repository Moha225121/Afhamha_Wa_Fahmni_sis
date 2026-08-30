<?php

use App\Http\Controllers\SupervisorPortal\AttendanceController;
use App\Http\Controllers\SupervisorPortal\DashboardController;
use App\Http\Controllers\SupervisorPortal\GuardianCallController;
use App\Http\Controllers\SupervisorPortal\StudentController;
use App\Http\Controllers\SupervisorPortal\StudentNoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('supervisor')->name('supervisor.')->middleware(['auth','supervisor'])->group(function (): void {
    Route::get('/', fn()=>redirect()->route('supervisor.dashboard'));
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/attendance', [AttendanceController::class,'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class,'store'])->name('attendance.store');
    Route::get('/attendance/history', [AttendanceController::class,'history'])->name('attendance.history');
    Route::get('/reports', [AttendanceController::class,'history'])->name('reports.index');
    Route::get('/students', [StudentController::class,'index'])->name('students.index');
    Route::get('/students/{student}', [StudentController::class,'show'])->name('students.show');
    Route::post('/students/{student}/notes', [StudentNoteController::class,'store'])->name('student-notes.store');
    Route::get('/student-notes', [StudentNoteController::class,'index'])->name('student-notes.index');
    Route::post('/student-notes', [StudentNoteController::class,'storeDirect'])->name('student-notes.direct-store');
    Route::get('/guardian-calls', [GuardianCallController::class,'index'])->name('guardian-calls.index');
    Route::post('/guardian-calls', [GuardianCallController::class,'storeDirect'])->name('guardian-calls.direct-store');
    Route::post('/students/{student}/guardian-calls', [GuardianCallController::class,'store'])->name('guardian-calls.store');
    Route::patch('/guardian-calls/{guardianCall}', [GuardianCallController::class,'update'])->name('guardian-calls.update');
});
