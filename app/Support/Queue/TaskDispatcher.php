<?php

namespace App\Support\Queue;

use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class TaskDispatcher
{
    /**
     * Поставить задачу в очередь с единым контрактом и идемпотентностью.
     *
     * @param  string  $type  Логический тип задачи (например, 'exam.research')
     * @param  Model|null  $subject  Субъект (Exam/Category/...), можно null
     * @param  array  $request  Произвольный request-пэйлоад для трассировки/повторов
     * @param  class-string  $jobClass  Класс джобы (implements ShouldQueue), __construct(int $taskId)
     * @param  string|null  $idempotencyKey  Уникальный ключ (если повторная постановка недопустима)
     * @param  string|null  $queue  Имя очереди (опционально)
     */
    public function enqueue(
        string $type,
        ?Model $subject,
        array $request,
        string $jobClass,
        ?string $idempotencyKey = null,
        ?string $queue = null
    ): GenerationTask {
        $examId = $request['exam_id']
            ?? ($subject instanceof Exam ? $subject->id : null);

        // Идемпотентность: если уже есть такой task — отдаем его
        if ($idempotencyKey) {
            $existing = GenerationTask::query()
                ->where('idempotency_key', $idempotencyKey)
                ->latest('id')
                ->first();

            if ($existing && in_array($existing->status, ['queued', 'running', 'completed'], true)) {
                return $existing;
            }
        }

        $task = GenerationTask::create([
            'exam_id' => $examId,
            'type' => $type,
            'status' => 'queued',
            'request' => $request,
            'attempts' => 0,
            'result' => null,
            'error' => null,
            'idempotency_key' => $idempotencyKey,
        ]);

        $job = new $jobClass($task->id);
        if ($queue && method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        // Стандартный helper dispatch()
        dispatch($job);

        Log::info("[TaskDispatcher] queued {$type}", ['task_id' => $task->id, 'queue' => $queue]);

        return $task;
    }
}
