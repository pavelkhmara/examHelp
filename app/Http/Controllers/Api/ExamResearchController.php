<?php

namespace App\Http\Controllers\Api;

use App\Facades\TaskDispatcher;
use App\Http\Controllers\Controller;
use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExamResearchController extends Controller
{
    public function research(Request $request, string $examId)
    {
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        $payload = [
            'exam_id' => $exam->id,
            'notes'   => (string) $request->input('notes', ''),
            'source'  => 'api',
        ];

        // стабильный idem-ключ на экзамен (можно добавить версионирование логики)
        $idem = 'exam:'.$exam->id.':research:v1';

        $task = TaskDispatcher::enqueue(
            type: 'exam.research',
            subject: $exam,
            request: $payload,
            jobClass: RunExamResearchJob::class,
            idempotencyKey: $idem,
            queue: null // или 'default'
        );

        return response()->json([
            'task_id' => $task->id,
            'status'  => $task->status,
        ], 202);
    }
}
