<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamDocument;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExamResearchController extends Controller
{
    // Внедряем TaskDispatcher через DI, чтобы не дергать enqueue статически
    public function research(Request $request, string $examId, TaskDispatcher $tasks)
    {
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        // Валидируем поля. Разрешаем либо document_id (uuid), либо документ как файл.
        $validated = $request->validate([
            'user_input' => ['nullable'], // строка JSON или уже массив — разберём ниже
            'notes' => ['nullable', 'string'],
            'document_id' => ['nullable', 'string', Rule::exists('exam_documents', 'id')],
            'document' => ['nullable', 'file', 'mimes:pdf,doc,docx,txt', 'max:10240'], // 10MB
            'without_confirmation' => ['nullable', 'boolean'], // Skip user confirmation, use AI auto-clarification
        ]);

        // Если пришли одновременно и файл, и id — это ошибка UX
        if (! empty($validated['document_id']) && $request->hasFile('document')) {
            throw ValidationException::withMessages([
                'document' => 'Передайте либо document_id, либо document (файл), но не оба сразу.',
            ]);
        }

        // Приводим user_input к массиву (разрешаем как строку JSON, так и уже массив)
        $userInput = $validated['user_input'] ?? [];
        if (is_string($userInput) && $userInput !== '') {
            $decoded = json_decode($userInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $userInput = $decoded;
            }
        } elseif (! is_array($userInput)) {
            $userInput = [];
        }

        // Extract without_confirmation from user_input if present (takes priority over top-level param)
        $withoutConfirmationFromInput = null;
        if (isset($userInput['without_confirmation'])) {
            $withoutConfirmationFromInput = (bool) $userInput['without_confirmation'];
            unset($userInput['without_confirmation']); // Remove from user_input to avoid duplication
        }

        $documentId = $validated['document_id'] ?? null;

        // Если прислали файл — кладём его в storage и создаём ExamDocument
        if ($request->hasFile('document')) {
            $file = $request->file('document'); // Illuminate\Http\UploadedFile

            $original = $file->getClientOriginalName() ?: 'document.bin';
            $ext = $file->getClientOriginalExtension() ?: 'bin';
            $filename = (string) Str::uuid().'.'.$ext;

            // Кладём на 'local' диск в папку documents/
            $path = $file->storeAs('documents', $filename, 'local');

            $doc = ExamDocument::query()->create([
                'id' => (string) Str::uuid(),
                'exam_id' => $exam->id,
                'generation_task_id' => null,
                'original_name' => $original,
                'disk' => 'local',
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'status' => 'uploaded',
                'extracted_text' => null,
                'error' => null,
                'meta' => [],
            ]);

            $documentId = $doc->id;

            // Dispatch extraction job
            // В тестах или при sync-драйвере — выполняем синхронно
            if (app()->environment('testing') || config('queue.default') === 'sync') {
                (new \App\Jobs\ExtractExamDocumentTextJob($doc->id))->handle();
            } else {
                \App\Jobs\ExtractExamDocumentTextJob::dispatch($doc->id);
            }
        }

        // Собираем полезную нагрузку для таска
        $payload = [
            'exam_id' => $exam->id,
            'source' => 'api',
            'notes' => (string) ($validated['notes'] ?? ''),
            'user_input' => $userInput,
            'document_id' => $documentId,
            // Priority: value from user_input > top-level param > false
            'without_confirmation' => $withoutConfirmationFromInput ?? (bool) ($validated['without_confirmation'] ?? false),
        ];

        // Кладём таск в очередь через инстанс сервиса
        $enq = $tasks->enqueue(
            type: 'research',
            subject: $exam,
            request: $payload,
            jobClass: \App\Jobs\RunExamResearchJob::class,
            idempotencyKey: "exam:{$exam->id}:research:".md5(json_encode($userInput).'|'.($documentId ?? 'none')),
            queue: null
        );

        // НОРМАЛИЗУЕМ task_id к int (enqueue может вернуть int|array|Model)
        $taskId = null;
        if (is_int($enq)) {
            $taskId = $enq;
        } elseif (is_array($enq)) {
            if (isset($enq['id']) && is_numeric($enq['id'])) {
                $taskId = (int) $enq['id'];
            } elseif (isset($enq['task_id']) && is_numeric($enq['task_id'])) {
                $taskId = (int) $enq['task_id'];
            } elseif (isset($enq['task']) && is_object($enq['task']) && isset($enq['task']->id)) {
                $taskId = (int) $enq['task']->id;
            }
        } elseif (is_object($enq)) {
            // Вдруг вернули модель GenerationTask
            if (isset($enq->id)) {
                $taskId = (int) $enq->id;
            } elseif (method_exists($enq, 'getKey')) {
                $taskId = (int) $enq->getKey();
            }
        }

        if (! is_int($taskId)) {
            // Последний предсказуемый fallback: бросим валидационную ошибку, чтобы не отдавать мусор
            throw ValidationException::withMessages([
                'enqueue' => 'Не удалось определить идентификатор задачи.',
            ]);
        }

        return response()->json(['task_id' => $taskId], 202);
    }

    /**
     * Confirm or reject exam identity after identity_guard hold
     *
     * POST /api/exams/{examId}/research/{taskId}/confirm-identity
     */
    public function confirmIdentity(Request $request, string $examId, int $taskId)
    {
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        /** @var \App\Models\GenerationTask $task */
        $task = \App\Models\GenerationTask::query()->findOrFail($taskId);

        // Verify task belongs to this exam
        if ($task->exam_id !== $exam->id) {
            abort(400, 'Task does not belong to this exam');
        }

        // Check if there's a hold to confirm
        $identity = $task->result['identity'] ?? null;
        if (! $identity || ! ($identity['hold'] ?? false)) {
            return response()->json([
                'error' => 'No identity hold to confirm',
                'current_status' => $task->status,
            ], 400);
        }

        $validated = $request->validate([
            'confirmed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $confirmed = $validated['confirmed'];
        $notes = $validated['notes'] ?? null;

        if ($confirmed) {
            // User confirmed the identity - remove hold and continue pipeline
            $identity['hold'] = false;
            $identity['user_confirmed'] = true;
            $identity['confirmed_at'] = now()->toISOString();
            if ($notes) {
                $identity['confirmation_notes'] = $notes;
            }

            $result = (array) ($task->result ?? []);
            $result['identity'] = $identity;
            $task->result = $result;
            $task->status = 'queued';  // Reset status so job can continue
            $task->save();

            // Continue the pipeline (dispatch job again to run structure building)
            \App\Jobs\RunExamResearchJob::dispatch($task->id)
                ->delay(now()->addSeconds(1));

            return response()->json([
                'status' => 'confirmed',
                'message' => 'Identity confirmed. Research pipeline will continue.',
                'continue' => true,
                'task_id' => $task->id,
            ]);
        } else {
            // User rejected - mark as uncertain and request clarification
            $identity['status'] = 'uncertain';
            $identity['confidence'] = 0.3;
            $identity['user_rejected'] = true;
            $identity['rejected_at'] = now()->toISOString();
            $identity['hold'] = true; // Keep hold until we get better info
            if ($notes) {
                $identity['rejection_notes'] = $notes;
            }

            $result = (array) ($task->result ?? []);
            $result['identity'] = $identity;
            $task->result = $result;
            $task->status = 'queued'; // Need more input
            $task->save();

            return response()->json([
                'status' => 'rejected',
                'message' => 'Identity rejected. Please provide additional information.',
                'followups' => $identity['followups'] ?? ['Please provide more specific information about the exam'],
                'task_id' => $task->id,
            ]);
        }
    }
}
