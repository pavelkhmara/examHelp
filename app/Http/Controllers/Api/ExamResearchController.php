<?php

namespace App\Http\Controllers\Api;

use App\Facades\TaskDispatcher;
use App\Http\Controllers\Controller;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamResearchController extends Controller
{
    public function research(Request $request, string $examId)
    {
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        $payload = [
            'exam_id' => $exam->id,
            'notes' => (string) $request->input('notes', ''),
            'source' => 'api',
        ];

        // стабильный idem-ключ на экзамен (можно добавить версионирование логики)
        $idem = 'exam:'.$exam->id.':research:v1';

        $task = TaskDispatcher::enqueue(
            type: 'research',
            subject: $exam,
            request: $payload,
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idem,
            queue: null
        );

        return response()->json([
            'task_id' => $task->id,
            'status' => $task->status,
        ], 202);
    }

    /**
     * Queue research pipeline for an exam.
     * POST /api/exams/{exam}/research
     */
    public function store(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'queued',
            'request' => ['notes' => $data['notes'] ?? null],
            'response' => [],
            'result' => [],
            'attempts' => 0,
        ]);

        // Вся тяжёлая работа — через очередь:
        RunExamResearchJob::dispatch($task->id)->onQueue('default');

        return response()->json([
            'task_id' => (int) $task->id,
            'status' => (string) $task->status,
        ], 202);
    }
}
