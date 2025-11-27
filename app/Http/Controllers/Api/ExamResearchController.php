<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamDocument;
use App\Support\Queue\TaskDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExamResearchController extends Controller
{
    // Внедряем TaskDispatcher через DI, чтобы не дергать enqueue статически
    public function research(Request $request, string $examId, TaskDispatcher $tasks): JsonResponse
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
            'overview_model' => ['nullable', 'string', 'in:gpt-5-mini,gpt-5'], // AI model for overview generation
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
            'overview_model' => $validated['overview_model'] ?? 'gpt-5-mini', // Default to gpt-5-mini for speed/cost
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
    public function confirmIdentity(Request $request, string $examId, int $taskId): JsonResponse
    {
        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        /** @var \App\Models\GenerationTask $task */
        $task = \App\Models\GenerationTask::query()->findOrFail($taskId);

        // Verify task belongs to this exam
        if ($task->exam_id !== $exam->id) {
            abort(400, 'Task does not belong to this exam');
        }

        // Extract identity from new structure (verification_attempts) or fallback to old
        $result = $task->result ?? [];
        $identity = null;

        if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
            $latestAttempt = end($result['verification_attempts']);
            $identity = $latestAttempt['identity_result'] ?? null;
        } else {
            $identity = $result['identity'] ?? null;
        }

        if (! $identity) {
            return response()->json([
                'error' => 'No identity data to confirm',
                'current_status' => $task->status,
            ], 400);
        }

        // Check if task is in pending_confirmation status
        if ($task->status !== 'pending_confirmation') {
            return response()->json([
                'error' => 'Task is not in pending_confirmation status',
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
            // User confirmed the identity - boost confidence and continue pipeline
            $originalConfidence = $identity['confidence'] ?? 0;
            $identity['user_confirmed'] = true;
            $identity['confirmed_at'] = now()->toISOString();
            $identity['hold'] = false; // CRITICAL: Reset hold so job doesn't pause again

            // Boost confidence to 1.0 if user manually confirmed
            if ($originalConfidence < 0.8) {
                $identity['confidence'] = 1.0;
                $identity['confidence_boosted_by'] = 'user_confirmation';
                $identity['original_confidence'] = $originalConfidence;
            }

            if ($notes) {
                $identity['confirmation_notes'] = $notes;
            }

            // Update identity in verification_attempts structure
            $result = (array) ($task->result ?? []);
            if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                $attemptIndex = count($result['verification_attempts']) - 1;
                $result['verification_attempts'][$attemptIndex]['identity_result'] = $identity;
            } else {
                // Fallback to old structure
                $result['identity'] = $identity;
            }

            $task->result = $result;
            $task->status = 'queued';  // Reset status so job can continue
            $task->save();

            // Add activity log
            $task->addActivity('user_confirmed_identity', 'User confirmed identity via API', [
                'original_confidence' => $originalConfidence,
                'notes' => $notes,
            ]);

            // Phase 9: Create ConfirmedIdentity for future reuse
            try {
                /** @var \App\Services\LanguageApp\ConfirmedIdentityService $confirmedIdentityService */
                $confirmedIdentityService = app(\App\Services\LanguageApp\ConfirmedIdentityService::class);
                $confirmedIdentity = $confirmedIdentityService->createConfirmedIdentity($exam, $identity, $task);

                $task->addActivity('confirmed_identity_created', 'ConfirmedIdentity record created', [
                    'confirmed_identity_id' => $confirmedIdentity->id,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to create ConfirmedIdentity in confirmIdentity', [
                    'exam_id' => $exam->id,
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }

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
            // User rejected - re-run identity verification
            $identity['status'] = 'uncertain';
            $identity['confidence'] = 0.3;
            $identity['user_rejected'] = true;
            $identity['rejected_at'] = now()->toISOString();
            $identity['user_provided_clarification'] = true; // Signal to job to re-run identity guard
            if ($notes) {
                $identity['rejection_notes'] = $notes;
                $identity['clarification_data'] = ['user_notes' => $notes];
            }

            // Update identity in verification_attempts structure
            $result = (array) ($task->result ?? []);
            if (isset($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                $attemptIndex = count($result['verification_attempts']) - 1;
                $result['verification_attempts'][$attemptIndex]['identity_result'] = $identity;
            } else {
                // Fallback to old structure
                $result['identity'] = $identity;
            }

            $task->result = $result;
            $task->status = 'queued'; // Need more input
            $task->save();

            // Add activity log
            $task->addActivity('user_rejected_identity', 'User rejected identity, re-running verification', [
                'notes' => $notes,
            ]);

            // Re-run the research job to verify identity again
            \App\Jobs\RunExamResearchJob::dispatch($task->id)
                ->delay(now()->addSeconds(1));

            return response()->json([
                'status' => 'rejected',
                'message' => 'Identity rejected. Re-running identity verification.',
                'task_id' => $task->id,
            ]);
        }
    }

    /**
     * Universal clarification endpoint for identity verification
     *
     * POST /api/exams/{examId}/research/{taskId}/clarify
     */
    public function clarify(Request $request, string $examId, int $taskId): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 1] Endpoint called', [
            'exam_id' => $examId,
            'task_id' => $taskId,
            'request_data' => $request->all(),
        ]);

        /** @var Exam $exam */
        $exam = Exam::query()->findOrFail($examId);

        /** @var \App\Models\GenerationTask $task */
        $task = \App\Models\GenerationTask::query()->findOrFail($taskId);

        // Verify task belongs to this exam
        if ($task->exam_id !== $exam->id) {
            abort(400, 'Task does not belong to this exam');
        }

        $validated = $request->validate([
            'clarification_type' => ['required', 'in:select_candidate,answer_questions,provide_fields,reject_all'],
            'selected_candidate' => ['required_if:clarification_type,select_candidate', 'array'],
            'answers' => ['required_if:clarification_type,answer_questions', 'array'],
            'user_input_updates' => ['required_if:clarification_type,provide_fields', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 2] Validation passed', [
            'clarification_type' => $validated['clarification_type'],
            'validated_data' => $validated,
        ]);

        $identity = $task->result['identity'] ?? [];

        switch ($validated['clarification_type']) {
            case 'select_candidate':
                // User selected from candidates - set as canonical and confirm
                $selectedCandidate = $validated['selected_candidate'];

                $identity['canonical'] = $selectedCandidate;
                $identity['confidence'] = 1.0;
                $identity['user_confirmed'] = true;
                $identity['user_selected_candidate'] = true;
                $identity['hold'] = false;
                $identity['status'] = 'certain';
                $identity['confirmed_at'] = now()->toISOString();

                if (! empty($validated['notes'])) {
                    $identity['confirmation_notes'] = $validated['notes'];
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'queued';
                $task->save();

                // Continue the pipeline
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return response()->json([
                    'status' => 'confirmed',
                    'message' => 'Exam variant selected. Research pipeline will continue.',
                    'continue' => true,
                    'task_id' => $task->id,
                    'identity' => $identity,
                ]);

            case 'answer_questions':
                \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 3] Processing answer_questions', [
                    'answers' => $validated['answers'],
                    'followups' => $identity['followups'] ?? [],
                ]);

                // Merge answers into user_input and re-run identity guard
                $currentInput = $task->request['user_input'] ?? [];

                // Format answers as Q&A pairs so AI understands the context
                $followups = $identity['followups'] ?? [];
                $clarificationText = "=== Additional Information (User Answers) ===\n\n";

                foreach ($validated['answers'] as $index => $answer) {
                    if (isset($followups[$index]) && ! empty(trim($answer))) {
                        $question = $followups[$index];
                        $questionText = is_string($question) ? $question : ($question['q'] ?? 'Question '.($index + 1));
                        $clarificationText .= "Q: {$questionText}\n";
                        $clarificationText .= "A: {$answer}\n\n";
                    }
                }

                \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 4] Formatted clarification text', [
                    'clarification_text' => $clarificationText,
                ]);

                // Add formatted Q&A to user_input as a single string field
                $currentInput['clarification'] = $clarificationText;

                $request_data = (array) ($task->request ?? []);
                $request_data['user_input'] = $currentInput;
                $task->request = $request_data;

                $identity['user_provided_clarification'] = true;
                $identity['clarification_provided_at'] = now()->toISOString();
                $identity['clarification_data'] = $validated['answers'];

                if (! empty($validated['notes'])) {
                    $identity['clarification_notes'] = $validated['notes'];
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'queued';
                $task->save();

                \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 5] Task updated and saved', [
                    'task_id' => $task->id,
                    'new_status' => $task->status,
                    'user_input' => $task->request['user_input'],
                    'user_provided_clarification' => $identity['user_provided_clarification'],
                ]);

                // Re-run identity verification with updated data
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                \Illuminate\Support\Facades\Log::info('🔍 [CLARIFY STEP 6] Job dispatched', [
                    'task_id' => $task->id,
                ]);

                return response()->json([
                    'status' => 'clarified',
                    'message' => 'Answers provided. Re-running identity verification.',
                    'task_id' => $task->id,
                ]);

            case 'provide_fields':
                // Merge field values into user_input
                $currentInput = $task->request['user_input'] ?? [];
                $updates = $validated['user_input_updates'];

                // Directly merge field updates (these are already key-value pairs)
                $mergedInput = array_merge($currentInput, $updates);

                $request_data = (array) ($task->request ?? []);
                $request_data['user_input'] = $mergedInput;
                $task->request = $request_data;

                $identity['user_provided_clarification'] = true;
                $identity['clarification_provided_at'] = now()->toISOString();
                $identity['clarification_data'] = $updates;

                if (! empty($validated['notes'])) {
                    $identity['clarification_notes'] = $validated['notes'];
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'queued';
                $task->save();

                // Re-run identity verification with updated data
                \App\Jobs\RunExamResearchJob::dispatch($task->id)
                    ->delay(now()->addSeconds(1));

                return response()->json([
                    'status' => 'clarified',
                    'message' => 'Information provided. Re-running identity verification.',
                    'task_id' => $task->id,
                ]);

            case 'reject_all':
                // User rejected all candidates - none match their exam
                $identity['status'] = 'rejected';
                $identity['user_rejected_all'] = true;
                $identity['rejected_at'] = now()->toISOString();

                if (! empty($validated['notes'])) {
                    $identity['rejection_notes'] = $validated['notes'];
                }

                $result = (array) ($task->result ?? []);
                $result['identity'] = $identity;
                $task->result = $result;
                $task->status = 'failed';
                $task->error = 'User rejected all exam variants - none match their exam. '.($validated['notes'] ?? '');
                $task->save();

                // Add activity log
                $task->addActivity('user_rejected_all_variants', 'User rejected all exam variants', [
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Update exam research status
                $exam->research_status = 'failed';
                $exam->save();

                return response()->json([
                    'status' => 'rejected',
                    'message' => 'All variants rejected. Research cancelled.',
                    'task_id' => $task->id,
                ]);
        }
    }

    /**
     * Get pending task for an exam (used by Nova Resource Tool)
     *
     * GET /api/exams/{examId}/pending-task
     */
    public function getPendingTask(string $examId): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('getPendingTask called', [
            'examId' => $examId,
            // 'user_id' => auth()->id() ?? 'not authenticated',
        ]);

        try {
            /** @var Exam $exam */
            $exam = Exam::query()->findOrFail($examId);

            \Illuminate\Support\Facades\Log::info('Exam found', ['exam_id' => $exam->id]);

            $task = \App\Models\GenerationTask::query()
                ->where('exam_id', $exam->id)
                ->whereIn('status', ['pending_confirmation', 'pending_clarification'])
                ->latest()
                ->first();

            if (! $task) {
                \Illuminate\Support\Facades\Log::info('No pending task found');

                return response()->json([
                    'task' => null,
                    'needs_clarification' => false,
                ]);
            }

            \Illuminate\Support\Facades\Log::info('Returning pending task', [
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

            // Normalize result structure for frontend compatibility
            // Frontend expects result.identity, but backend stores result.verification_attempts[0].identity_result
            $result = $task->result;
            if (is_array($result) && isset($result['verification_attempts']) && is_array($result['verification_attempts']) && ! empty($result['verification_attempts'])) {
                // Extract identity from latest verification attempt
                $latestAttempt = end($result['verification_attempts']);
                if (isset($latestAttempt['identity_result'])) {
                    $result['identity'] = $latestAttempt['identity_result'];
                }
            }

            return response()->json([
                'task' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'type' => $task->type,
                    'result' => $result,
                    'created_at' => $task->created_at?->toISOString(),
                    'updated_at' => $task->updated_at?->toISOString(),
                ],
                'needs_clarification' => true,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('getPendingTask error', [
                'examId' => $examId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
