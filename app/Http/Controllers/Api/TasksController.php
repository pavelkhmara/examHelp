<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenerationTask;

class TasksController extends Controller
{
    public function show(int $id)
    {
        $task = GenerationTask::query()->findOrFail($id);

        return response()->json([
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status,
            'result' => $task->result,
            'error' => $task->error,
            'exam_id' => $task->exam_id,
        ]);
    }
}
