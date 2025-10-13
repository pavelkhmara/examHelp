<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LanguageApp\ExamController;
use Illuminate\Http\Request;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/exams/{exam}/research', [ExamController::class, 'research']);
    Route::get('/tasks/{task}', [ExamController::class, 'task']);

    Route::get('/me', function (Request $request) {
        $u = $request->user();
        return response()->json([
            'id' => $u->id,
            'email' => $u->email,
            'roles' => $u->getRoleNames(), // spatie/permission
        ]);
    });
});


use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Support\Facades\Log;
use App\Models\GenerationLog;
Route::post('/debug/research-now/{exam}', function (Exam $exam, ExamResearchService $svc) {
    abort_unless(App::environment('local') || App::environment('development'), 403, 'Forbidden in this environment');

    $task = GenerationTask::create([
        'exam_id' => $exam->id,
        'type'    => 'research',
        'status'  => 'running',
    ]);

    try {
        $result = $svc->runPipeline($exam, $task); // внутри должен дернуть callAi()

        // Если хотите увидеть сырой ответ — запишем в GenerationLog
        if (is_array($result) && isset($result['raw'])) {
            GenerationLog::create([
                'generation_task_id' => $task->id,
                'stage'              => 'overview_raw',
                'payload'            => ['raw' => $result['raw']],
                'message'            => 'Raw AI response snapshot',
            ]);
        }

        $task->update(['status' => 'completed']);
        return response()->json(['ok' => true, 'task_id' => $task->id]);
    } catch (\Throwable $e) {
        $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
        Log::error('Debug research failed', ['task_id' => $task->id, 'error' => $e]);
        return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
});

