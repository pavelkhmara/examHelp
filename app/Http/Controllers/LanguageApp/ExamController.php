<?php

namespace App\Http\Controllers\LanguageApp;

use App\Http\Controllers\Controller;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function research(Request $request, Exam $exam)
    {
        // права можно ограничить ролями (spatie/permission) — по необходимости
        RunExamResearchJob::dispatch($exam->id, (string) $request->input('notes'));
        return response()->json(['ok' => true, 'queued' => true]);
    }

    public function task(GenerationTask $task)
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
