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
Route::get('/diagnostics-dashboard/compare/{genId}/{refId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'compareExams'])
    ->name('diagnostics.compare');
Route::get('/diagnostics-dashboard/reference-exams', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'referenceExams'])
    ->name('diagnostics.reference-exams');

// Golden Stage Fixtures & Snapshots
Route::get('/diagnostics-dashboard/golden-fixtures', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'goldenFixtures'])
    ->name('diagnostics.golden-fixtures');
Route::get('/diagnostics-dashboard/golden-compare/{examId}/{fixtureId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'goldenCompare'])
    ->name('diagnostics.golden-compare');
Route::get('/diagnostics-dashboard/golden-compare/{examId}/{fixtureId}/{stage}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'goldenCompareStage'])
    ->name('diagnostics.golden-compare-stage');
Route::post('/diagnostics-dashboard/snapshot/capture/{examId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'snapshotCapture'])
    ->name('diagnostics.snapshot-capture');
Route::get('/diagnostics-dashboard/snapshot/list/{examId?}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'snapshotList'])
    ->name('diagnostics.snapshot-list');
Route::get('/diagnostics-dashboard/snapshot/compare/{examId}/{stage}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'snapshotCompare'])
    ->name('diagnostics.snapshot-compare');
