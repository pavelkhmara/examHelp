<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});
Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

use App\Http\Controllers\Web\ExamPlayController;

Route::get('/exams', [ExamPlayController::class, 'index'])->name('exams.index');
Route::get('/exams/{exam}', [ExamPlayController::class, 'show'])->name('exams.show');
Route::get('/attempts/{attempt}', [ExamPlayController::class, 'play'])->name('attempts.play');
Route::get('/attempts/{attempt}/result', [ExamPlayController::class, 'result'])->name('attempts.result');

Route::get('/login', function () {
    return redirect('/nova/login');
})->name('login');

// Diagnostics Dashboard
Route::get('/diagnostics-dashboard', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'index'])
    ->name('diagnostics.dashboard');
Route::get('/diagnostics-dashboard/exam/{examId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'diagnoseExam'])
    ->name('diagnostics.exam');
