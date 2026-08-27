<?php

use App\Http\Controllers\TeacherPortal\AssignmentController;
use App\Http\Controllers\TeacherPortal\AttendanceController;
use App\Http\Controllers\TeacherPortal\DashboardController;
use App\Http\Controllers\TeacherPortal\ExamController;
use App\Http\Controllers\TeacherPortal\GradeController;
use App\Http\Controllers\TeacherPortal\ProfileController;
use App\Http\Controllers\TeacherPortal\StudentController;
use Illuminate\Support\Facades\Route;

Route::prefix('teacher')->name('teacher.')->middleware(['auth', 'teacher'])->group(function (): void {
    Route::get('/', fn () => redirect()->route('teacher.dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/index', fn () => redirect()->route('teacher.dashboard'))->name('dashboard.index');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/{student}', [StudentController::class, 'show'])->name('students.show');

    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');

    Route::get('/exams', [ExamController::class, 'index'])->name('exams.index');
    Route::get('/exams/create', [ExamController::class, 'create'])->name('exams.create');
    Route::post('/exams', [ExamController::class, 'store'])->name('exams.store');
    Route::get('/exams/{exam}/edit', [ExamController::class, 'edit'])->name('exams.edit');
    Route::put('/exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
    Route::delete('/exams/{exam}', [ExamController::class, 'destroy'])->name('exams.destroy');
    Route::patch('/exams/{exam}/status', [ExamController::class, 'status'])->name('exams.status');

    Route::get('/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::get('/assignments/create', [AssignmentController::class, 'create'])->name('assignments.create');
    Route::post('/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::get('/assignments/{assignment}/edit', [AssignmentController::class, 'edit'])->name('assignments.edit');
    Route::get('/assignments/{assignment}', [\App\Http\Controllers\TeacherPortal\AssignmentSubmissionController::class, 'show'])->name('assignments.show');
    Route::put('/assignments/{assignment}', [AssignmentController::class, 'update'])->name('assignments.update');
    Route::get('/assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])->name('assignments.submissions');
    Route::post('/assignments/{assignment}/submissions', [AssignmentController::class, 'submissionsStore'])->name('assignments.submissions.store');
    Route::get('/submissions/{submission}/file', [\App\Http\Controllers\TeacherPortal\AssignmentSubmissionController::class, 'download'])->middleware('throttle:60,1')->name('submissions.file');

    Route::get('/grades', [GradeController::class, 'index'])->name('grades.index');
    Route::post('/grades', [GradeController::class, 'store'])->name('grades.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
