<?php

namespace App\Jobs\Traits;

use App\Models\GenerationLog;
use App\Models\GenerationTask;
use Illuminate\Support\Facades\Log;

trait InteractsWithGenerationTask
{
    protected function asRunning(GenerationTask $task): void
    {
        $task->status = 'running';
        $task->attempts = (int) $task->attempts + 1;
        $task->save();
    }

    protected function asCompleted(GenerationTask $task, ?array $result = null): void
    {
        $task->status = 'completed';
        if ($result !== null) {
            $task->result = $result;
        }
        $task->error = null;
        $task->save();
    }

    protected function asFailed(GenerationTask $task, \Throwable $e): void
    {
        $task->status = 'failed';
        $task->error = substr($e->getMessage(), 0, 2000);
        $task->save();
        Log::error('[GenerationTask] failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);
    }

    protected function logStage(GenerationTask $task, string $stage, array $request = null, array $response = null): void
    {
        GenerationLog::create([
            'generation_task_id' => $task->id,
            'stage'              => $stage,
            'request'            => $request,
            'response'           => $response,
            'prompt_tokens'      => 0,
            'completion_tokens'  => 0,
            'total_tokens'       => 0,
        ]);
    }
}
