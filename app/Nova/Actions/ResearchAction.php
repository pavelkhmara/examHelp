<?php

namespace App\Nova\Actions;

use App\Support\Queue\TaskDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class ResearchAction extends Action
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $name = 'Run Exam Research';

    public $standalone = true;

    public function fields(NovaRequest $request)
    {
        return [
            Boolean::make('Without Confirmation', 'without_confirmation')
                ->help('Skip identity confirmation step and auto-approve exam identity')
                ->default(true),

            Select::make('Overview Model', 'overview_model')
                ->options([
                    'gpt-5-mini' => 'GPT-5 Mini (faster, cheaper)',
                    'gpt-5' => 'GPT-5 (highest quality, slower)',
                ])
                ->default('gpt-5-mini')
                ->help('AI model for exam overview generation'),
        ];
    }

    public function handle(ActionFields $fields, $models)
    {
        /** @var TaskDispatcher $tasks */
        $tasks = app(TaskDispatcher::class);

        $createdCount = 0;
        $existingCount = 0;

        /** @var \App\Models\Exam $exam */
        foreach ($models as $exam) {
            // Parse user_input from exam
            $userInput = [];
            if (!empty($exam->user_input)) {
                if (is_array($exam->user_input)) {
                    $userInput = $exam->user_input;
                } elseif (is_string($exam->user_input)) {
                    $decoded = json_decode($exam->user_input, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $userInput = $decoded;
                    }
                }
            }

            // Get last uploaded document if exists
            $documentId = null;
            $lastDoc = $exam->documents()->latest()->first();
            if ($lastDoc) {
                $documentId = $lastDoc->id;
            }

            // Build payload
            $payload = [
                'exam_id' => $exam->id,
                'source' => 'nova_action',
                'user_input' => $userInput,
                'document_id' => $documentId,
                'without_confirmation' => $fields->without_confirmation ?? true,
                'overview_model' => $fields->overview_model ?? 'gpt-5-mini',
            ];

            // Enqueue research task with unique key (timestamp ensures no deduplication)
            $task = $tasks->enqueue(
                type: 'research',
                subject: $exam,
                request: $payload,
                jobClass: \App\Jobs\RunExamResearchJob::class,
                idempotencyKey: "exam:{$exam->id}:research:nova:" . time(),
                queue: null
            );

            // Check if this is a newly created task or existing one
            // A task is considered "new" if it was created within the last 10 seconds
            $isNew = $task->created_at->gt(now()->subSeconds(10));

            if ($isNew) {
                $createdCount++;
            } else {
                $existingCount++;
            }
        }

        // Return appropriate message
        if ($existingCount > 0 && $createdCount === 0) {
            return Action::message('ℹ️ A research task is already running for this exam. Please wait for it to complete or use "Reset & Restart Research" to force a new run.');
        } elseif ($existingCount > 0) {
            return Action::message("✅ {$createdCount} new task(s) started! {$existingCount} exam(s) already have tasks running. 🔄 Refresh to see progress.");
        } else {
            return Action::message('✅ Research task started! 🔄 Refresh this page to see progress.');
        }
    }
}
