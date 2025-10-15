<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Http\Controllers\LanguageApp\ExamController;

use App\Services\LanguageApp\ExamResearchService;
use App\Services\LanguageApp\AiProviderFactory;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Models\GenerationLog;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;



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



// ===== DEBUG routes =====
Route::prefix('debug')->group(function () {


    Route::post('/research-now/{exam}', function (Exam $exam, ExamResearchService $svc) {
        abort_unless(App::environment('local') || App::environment('development'), 403, 'Forbidden in this environment');
    
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type'    => 'research',
            'status'  => 'running',
        ]);
    
        try {
            $result = $svc->runPipeline($exam, $task); // go for callAi()
    
            if (is_array($result) && isset($result['raw'])) {
                GenerationLog::create([
                    'generation_task_id' => $task->id,
                    'stage'              => 'overview_raw',
                    'payload'            => ['raw' => $result['raw']],
                    'message'            => 'Raw AI response snapshot',
                ]);
            }
    
            $task->update(['status' => 'completed']);
            $exam->update(['status' => 'completed']);
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
            'error'  => $t->error,
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
            'exam'      => $exam->id,
            'task_id'   => optional($task)->id,
            'status'    => optional($task)->status,
            'structure' => optional($task)->result, // sections[], total_score{}
        ]);
    });



    Route::get('/ai', function (Exam $exam) {
        $cfg = config('ai');
        $client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 60,
        ]);
        try {
            $res = $client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $cfg['api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => $cfg['model'],
                    'messages'   => [
                        ['role' => 'user', 'content' => 'Return ONLY a single valid JSON object with three keys and success value.']
                    ],
                    // 'response_format' => [
                    //     'type' => 'json_schema',
                    //     'json_schema' => $schema
                    // ],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('AI HTTP error: ' . $e->getMessage());
        }

        $status = $res->getStatusCode();
        $raw    = (string) $res->getBody();

        $jsonData = json_decode($raw, true);
    
        return response()->json($jsonData, $status, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    });
});