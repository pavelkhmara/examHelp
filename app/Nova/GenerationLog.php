<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class GenerationLog extends Resource
{
    public static $model = \App\Models\GenerationLog::class;

    public static $title = 'id';

    public static $search = ['id', 'stage'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return ! $request->isResourceIndexRequest() && ! $request->isResourceDetailRequest();
                }),
            BelongsTo::make('Task', 'task', GenerationTask::class)->searchable(),
            Text::make('Stage')->sortable(),

            // Prompt field - formatted and readable
            Textarea::make('Prompt')
                ->resolveUsing(function ($value, $resource) {
                    return $this->extractPrompt($resource);
                })
                ->readonly()
                ->rows(15)
                ->hideFromIndex()
                ->help('AI prompt in readable format'),

            Code::make('Request')->json()
                ->resolveUsing(function ($v) {
                    $data = is_string($v) ? json_decode($v, true) : $v;
                    // Remove messages/sent_messages from request display
                    if (is_array($data)) {
                        unset($data['messages'], $data['sent_messages']);
                    }

                    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                })
                ->hideFromIndex()
                ->help('Request metadata (without prompt)'),

            Code::make('Response')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->hideFromIndex(),
            Number::make('Prompt Tokens'),
            Number::make('Completion Tokens'),
            Number::make('Total Tokens'),
            DateTime::make('Created At')->sortable(),
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new Filters\ExamFilter,
        ];
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        if ($examId = $request->get('exam')) {
            $query->where('exam_id', $examId);
        }

        return $query;
    }

    /**
     * Extract and format AI prompt from request data for readable display
     */
    protected function extractPrompt($resource): string
    {
        $request = $resource->request;

        // Handle string or array
        if (is_string($request)) {
            $request = json_decode($request, true);
        }

        if (! is_array($request)) {
            return 'No prompt data available';
        }

        // Extract messages array (could be 'messages' or 'sent_messages')
        $messages = $request['messages'] ?? $request['sent_messages'] ?? $request['request']['messages'] ?? $request['request']['sent_messages'] ?? $request['request'] ?? $request ?? null;

        if (! is_array($messages) || empty($messages)) {
            return 'No messages found in request';
        }

        // Format messages in readable way
        $formatted = [];
        $messageNumber = 1;
        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';

            // Convert content to readable format
            if (is_array($content)) {
                // Handle multimodal content (text + images)
                $parts = [];
                foreach ($content as $part) {
                    if (isset($part['type']) && $part['type'] === 'text') {
                        $parts[] = $part['text'] ?? '';
                    } elseif (isset($part['type']) && $part['type'] === 'image_url') {
                        $parts[] = '[IMAGE]';
                    }
                }
                $content = implode("\n", $parts);
            }

            // Unescape newlines and other escape sequences for readability
            $content = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $content);

            $formatted[] = '=== Message '.$messageNumber.' (Role: '.strtoupper($role).") ===\n".$content;
            $messageNumber++;
        }

        return implode("\n\n".str_repeat('-', 80)."\n\n", $formatted);
    }
}
