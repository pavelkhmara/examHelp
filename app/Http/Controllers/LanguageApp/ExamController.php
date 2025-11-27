<?php

namespace App\Http\Controllers\LanguageApp;

use App\Http\Controllers\Controller;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function research(Request $request, Exam $exam): \Illuminate\Http\JsonResponse
    {
        // права можно ограничить ролями (spatie/permission) — по необходимости
        // Note: This is legacy code - modern approach uses TaskDispatcher in ExamResearchController
        // Create task first, then dispatch with task ID
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued',
            'request' => ['notes' => $request->input('notes')],
        ]);

        RunExamResearchJob::dispatch($task->id);

        return response()->json(['ok' => true, 'queued' => true, 'task_id' => $task->id]);
    }

    public function task(GenerationTask $task): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status,
            'error' => $task->error,
            'created_at' => $task->created_at,
        ]);
    }
}
