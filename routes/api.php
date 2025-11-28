<?php

use App\Http\Controllers\Api\DiagnosticsController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamResearchController;
use App\Http\Controllers\Api\ScoringController;
use App\Http\Controllers\Api\StructureController;
use App\Http\Controllers\Api\TasksController;
use App\Models\Exam;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Http\Middleware\Authenticate;

Route::get('/health', function () {
    // Check database connectivity
    $dbOk = false;
    $dbError = null;
    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
        $dbOk = true;
    } catch (\Exception $e) {
        $dbError = $e->getMessage();
    }

    // Check Redis connectivity
    $redisOk = false;
    $redisError = null;
    try {
        \Illuminate\Support\Facades\Redis::ping();
        $redisOk = true;
    } catch (\Exception $e) {
        $redisError = $e->getMessage();
    }

    // Check API keys configuration
    $openaiKey = config('ai.api_key');
    $ttsKey = config('ai.tts.api_key') ?: config('ai.api_key');
    $unsplashKey = config('ai.images.unsplash.api_key');

    // Overall status
    $status = ($dbOk && $redisOk) ? 'healthy' : 'degraded';

    $checks = [
        'database' => $dbOk ? 'ok' : 'fail',
        'redis' => $redisOk ? 'ok' : 'fail',
        'openai_api_key' => $openaiKey ? 'configured' : 'missing',
        'tts' => [
            'enabled' => config('ai.tts.enabled', false),
            'api_key' => $ttsKey ? 'configured' : 'missing',
        ],
        'images' => [
            'enabled' => config('ai.images.enabled', false),
            'unsplash_api_key' => $unsplashKey ? 'configured' : 'missing',
        ],
        'queue_connection' => config('queue.default'),
        'ai_provider' => config('ai.default'),
    ];

    // Add error details if any
    if (! $dbOk) {
        $checks['database_error'] = $dbError;
    }
    if (! $redisOk) {
        $checks['redis_error'] = $redisError;
    }

    return response()->json([
        'status' => $status,
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $status === 'healthy' ? 200 : 503);
});

// Route::get('/exams', [ExamController::class, 'index'])->name('exams.index');
// Route::get('/exams/{exam}', [ExamController::class, 'show'])->name('exams.show');

// Route::post('/exams/{id}/research', [ExamResearchController::class, 'research'])
//     ->withoutMiddleware([\Illuminate\Auth\Middleware\Authenticate::class, 'auth:sanctum']);

// Route::get('/tasks/{id}', [TasksController::class, 'show'])
//     ->withoutMiddleware([\Illuminate\Auth\Middleware\Authenticate::class, 'auth:sanctum']);

// Route::get('/exams/{exam}/structure', [StructureController::class, 'show'])
//     ->withoutMiddleware(['auth', 'auth:sanctum', Authenticate::class])
//     ->name('exams.structure');

// Route::post('/score/preview', [ScoringController::class, 'preview'])
//     ->withoutMiddleware(['auth', 'auth:sanctum', Authenticate::class])
//     ->name('score.preview');

// Route::post('/evaluate/text', [EvaluationController::class, 'evaluateText'])
//     ->withoutMiddleware(['auth', 'auth:sanctum', \Laravel\Nova\Http\Middleware\Authenticate::class])
//     ->name('evaluate.text');

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', function (Request $request) {
        $u = $request->user();

        return response()->json([
            'id' => $u->id,
            'email' => $u->email,
            'roles' => $u->getRoleNames(), // spatie/permission
        ]);
    });

    Route::post('/exams', [ExamController::class, 'store'])
        ->name('exams.store');

    Route::post('/exams/{exam}/documents', [DocumentController::class, 'store'])
        ->name('exams.documents.store');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');

    Route::post('/exams/{examId}/research/{taskId}/confirm-identity', [ExamResearchController::class, 'confirmIdentity'])
        ->name('exams.research.confirm-identity');
});

// Nova-specific endpoints - use web middleware (session-based auth like Nova itself)
Route::middleware(['web'])->group(function () {
    // ExamWorkflowHub card endpoints
    Route::get('/nova-vendor/exam-status/{exam}', [\App\Http\Controllers\Api\ExamStatusController::class, 'show'])
        ->name('nova.exam-status');

    Route::post('/exams/{examId}/research/{taskId}/confirm-identity', [ExamResearchController::class, 'confirmIdentity'])
        ->name('exams.research.confirm-identity-web');

    Route::post('/exams/{examId}/research/{taskId}/clarify', [ExamResearchController::class, 'clarify'])
        ->name('exams.research.clarify');

    Route::get('/exams/{examId}/pending-task', [ExamResearchController::class, 'getPendingTask'])
        ->name('exams.pending-task');

    // Missing fields card endpoints
    Route::post('/exams/{exam}/update-fields', [ExamController::class, 'updateFields'])
        ->name('exams.update-fields');

    Route::post('/exams/{exam}/dismiss-card', [ExamController::class, 'dismissCard'])
        ->name('exams.dismiss-card');
});

Route::post('/exams/{id}/research', [ExamResearchController::class, 'research'])
    ->withoutMiddleware([\Illuminate\Auth\Middleware\Authenticate::class, 'auth:sanctum']);

Route::get('/tasks/{id}', [TasksController::class, 'show'])
    ->withoutMiddleware([\Illuminate\Auth\Middleware\Authenticate::class, 'auth:sanctum']);

Route::get('/exams/{exam}/structure', [StructureController::class, 'show'])
    ->withoutMiddleware(['auth', 'auth:sanctum', Authenticate::class])
    ->name('exams.structure');

Route::post('/score/preview', [ScoringController::class, 'preview'])
    ->withoutMiddleware(['auth', 'auth:sanctum', Authenticate::class])
    ->name('score.preview');

Route::post('/evaluate/text', [EvaluationController::class, 'evaluateText'])
    ->withoutMiddleware(['auth', 'auth:sanctum', \Laravel\Nova\Http\Middleware\Authenticate::class])
    ->name('evaluate.text');

// ===== DEBUG routes =====
Route::prefix('debug')->group(function () {

    Route::post('/research-now/{exam}', function (Exam $exam, ExamResearchService $svc) {
        abort_unless(App::environment('local') || App::environment('development'), 403, 'Forbidden in this environment');

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        try {
            $result = $svc->runPipeline($exam, $task); // go for callAi()

            if (is_array($result) && isset($result['raw'])) {
                GenerationLog::create([
                    'exam_id' => $task->exam_id,
                    'generation_task_id' => $task->id,
                    'stage' => 'overview_raw',
                    'request' => null,
                    'response' => ['message' => 'Raw AI response snapshot', 'raw' => $result['raw']],
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                ]);
            }

            $task->update(['status' => 'completed']);
            $exam->update(['research_status' => 'completed']);
            Log::debug('req', ['result' => $result]);

            return response()->json(['ok' => true, 'task_id' => $task->id]);
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('Debug research failed', ['task_id' => $task->id, 'error' => $e]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    });

    // Route::get('tasks/{task}', function (GenerationTask $task) {
    //     $task->load(['logs' => function($q) { $q->orderBy('id'); }]);
    //     return response()->json([
    //         'id' => $task->id,
    //         'status' => $task->status,
    //         'error' => $task->error,
    //         'request' => $task->request,
    //         'response' => $task->response,
    //         'result' => $task->result,
    // 'logs' => $task->logs->map(function($l){
    //     return [
    //         'id' => $l->id,
    //         'stage' => $l->stage,
    //         'request' => $l->request,
    //         'response' => $l->response,
    //         'prompt_tokens' => $l->prompt_tokens,
    //         'completion_tokens' => $l->completion_tokens,
    //         'total_tokens' => $l->total_tokens,
    //         'created_at' => $l->created_at,
    //     ];
    // }),
    //     ]);
    // });

    Route::get('/tasks/{id}', function ($id) {
        $t = \App\Models\GenerationTask::findOrFail($id);

        return response()->json([
            'status' => $t->status,
            'error' => $t->error,
            'result' => $t->result,
        ]);
    });

    // Route::get('/tasks/{task}', function (GenerationTask $task) {

    //     $logs = $task->logs()->orderBy('id')->get(['generation_task_id','stage','request','response','prompt_tokens','completion_tokens','total_tokens','payload']);
    //     return response()->json([
    //         'generation_task_id'=> $task->generation_task_id,
    //         'status'            => optional($task)->status,
    //         'request'           => $task->request,
    //         'response'          => $task->response,
    //         'error'             => optional($task)->error,
    //         'result'            => optional($task)->result,
    //         'stage'             => $task->stage,
    //         'prompt_tokens'     => optional($task)->prompt_tokens,
    //         'completion_tokens' => optional($task)->completion_tokens,
    //         'total_tokens'      => optional($task)->total_tokens,
    //         'logs'              => $logs,

    //     ]);
    // });

    // Route::get('/exams/{exam:uuid}/structure', function (\App\Models\Exam $exam) {

    //     return response()->json([
    //         'uuid'              => $exam->uuid,
    //         'slug'              => $exam->slug,
    //         'research_status'   => $exam->research_status,
    //         'title'             => optional($exam)->title,
    //         'description'       => optional($exam)->description,
    //         'level'             => optional($exam)->level,
    //         'is_active'         => optional($exam)->is_active,
    //         'sources'           => optional($exam)->sources,
    //         'meta'              => optional($exam)->meta,
    //         'categories_count'  => optional($exam)->categories_count,
    //         'examples_count'    => optional($exam)->examples_count,
    //     ]);
    // });

    Route::get('/exams/{exam}/structure', function (Exam $exam) {
        $task = \App\Models\GenerationTask::where('exam_id', $exam->id)->latest()->first();

        return response()->json([
            'exam' => $exam->id,
            'task_id' => optional($task)->id,
            'status' => optional($task)->status,
            'structure' => optional($task)->result, // sections[], total_score{}
            'research_status' => $exam->research_status,
        ]);
    });

    Route::get('/ai', function (Exam $exam) {
        $cfg = config('ai');
        $client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 60,
        ]);
        try {
            $res = $client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$cfg['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $cfg['model'],
                    'messages' => [
                        ['role' => 'user', 'content' => 'Return ONLY a single valid JSON object with three keys and success value.'],
                    ],
                    // 'response_format' => [
                    //     'type' => 'json_schema',
                    //     'json_schema' => $schema
                    // ],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('AI HTTP error: '.$e->getMessage());
        }

        $status = $res->getStatusCode();
        $raw = (string) $res->getBody();

        $jsonData = json_decode($raw, true);

        return response()->json($jsonData, $status, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    });
});

// ===== DIAGNOSTICS routes (public, read-only) =====
Route::prefix('diagnostics')->group(function () {
    // Structure recovery endpoints
    Route::get('/structure-recovery/scan', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'scanStructureRecovery']);
    Route::get('/structure-recovery/diagnose/{examId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'diagnoseStructure']);
    Route::post('/structure-recovery/recover/{examId}', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'recoverStructure']);
    Route::post('/structure-recovery/recover-all', [\App\Http\Controllers\DiagnosticsDashboardController::class, 'recoverAll']);

    Route::get('/health', [DiagnosticsController::class, 'health']);
    Route::get('/stats', [DiagnosticsController::class, 'stats']);
    Route::get('/activity', [DiagnosticsController::class, 'activity']);
    Route::get('/actions', [DiagnosticsController::class, 'actions']);
    Route::get('/queue', [DiagnosticsController::class, 'queueInfo']);

    Route::get('/exams', [DiagnosticsController::class, 'exams']);
    Route::get('/exams/{examId}', [DiagnosticsController::class, 'exam']);
    Route::get('/exams/{examId}/tasks', [DiagnosticsController::class, 'examTasks']);
    Route::get('/exams/{examId}/logs', [DiagnosticsController::class, 'examLogs']);
    Route::get('/exams/{examId}/categories', [DiagnosticsController::class, 'examCategories']);
    Route::get('/exams/{examId}/examples', [DiagnosticsController::class, 'examExamples']);

    Route::get('/tasks/{taskId}', [DiagnosticsController::class, 'task']);
});

// ===== TASK MANAGEMENT routes (public, write operations) =====
Route::prefix('tasks')->group(function () {
    Route::post('/{taskId}/cancel', [\App\Http\Controllers\Api\TaskManagementController::class, 'cancelTask']);
    Route::post('/{taskId}/retry', [\App\Http\Controllers\Api\TaskManagementController::class, 'retryTask']);
    Route::post('/{taskId}/force-complete', [\App\Http\Controllers\Api\TaskManagementController::class, 'forceCompleteTask']);
    Route::get('/stuck', [\App\Http\Controllers\Api\TaskManagementController::class, 'getStuckTasks']);
});

Route::prefix('exams')->group(function () {
    Route::post('/{examId}/tasks/cancel-all', [\App\Http\Controllers\Api\TaskManagementController::class, 'cancelAllExamTasks']);
    Route::post('/{examId}/research/force-start', [\App\Http\Controllers\Api\TaskManagementController::class, 'forceStartResearch']);
});

Route::get('/diagnostics/system-config', [\App\Http\Controllers\Api\TaskManagementController::class, 'getSystemConfig']);

// ===== MOBILE API routes =====
Route::prefix('mobile')->group(function () {
    // Export exam for mobile app
    Route::get('/exams/{exam}/export', [\App\Http\Controllers\Api\ExportController::class, 'export'])
        ->name('mobile.exams.export');

    // Session management
    Route::post('/sessions/start', [\App\Http\Controllers\Api\SessionController::class, 'start'])
        ->name('mobile.sessions.start');
    Route::get('/sessions/{session}', [\App\Http\Controllers\Api\SessionController::class, 'show'])
        ->name('mobile.sessions.show');
    Route::post('/sessions/{session}/finish', [\App\Http\Controllers\Api\SessionController::class, 'finish'])
        ->name('mobile.sessions.finish');
    Route::get('/sessions/{session}/answers', [\App\Http\Controllers\Api\SessionController::class, 'answers'])
        ->name('mobile.sessions.answers');
    Route::get('/sessions/{session}/result', [\App\Http\Controllers\Api\SessionController::class, 'result'])
        ->name('mobile.sessions.result');

    // Answer submission
    Route::post('/answers/submit', [\App\Http\Controllers\Api\AnswerController::class, 'submit'])
        ->name('mobile.answers.submit');
    Route::get('/answers/{answer}', [\App\Http\Controllers\Api\AnswerController::class, 'show'])
        ->name('mobile.answers.show');

    // Recommendations
    Route::get('/sessions/{session}/recommendations', [\App\Http\Controllers\Api\RecommendationController::class, 'forSession'])
        ->name('mobile.sessions.recommendations');
    Route::post('/sessions/{session}/recommendations/regenerate', [\App\Http\Controllers\Api\RecommendationController::class, 'regenerate'])
        ->name('mobile.sessions.recommendations.regenerate');
    Route::get('/users/{userId}/recommendations', [\App\Http\Controllers\Api\RecommendationController::class, 'forUser'])
        ->name('mobile.users.recommendations');
});
