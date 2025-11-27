<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * @extends resource<\App\Models\GenerationTask>
 */
class GenerationTask extends Resource
{
    public static string $model = \App\Models\GenerationTask::class;

    public static $title = 'id';

    public static $search = ['id', 'type', 'status'];

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
            Text::make('Type')->sortable(),
            Select::make('Status')->options([
                'queued' => 'queued',
                'running' => 'running',
                'completed' => 'completed',
                'failed' => 'failed',
                'pending_confirmation' => 'pending_confirmation',
                'pending_clarification' => 'pending_clarification',
                'cancelled' => 'cancelled',
            ])->displayUsingLabels()->sortable(),
            Number::make('Attempts')->sortable(),
            Code::make('Request')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->hideFromIndex(),
            Code::make('Response')->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->hideFromIndex(),
            Code::make('Activities')
                ->resolveUsing(function () {
                    $formattedActivities = $this->resource->getFormattedActivities();

                    if (empty($formattedActivities)) {
                        return '[]';
                    }

                    // Format activities as human-readable list with [DD.MM HH:MM:SS] format
                    $formatted = [];
                    foreach ($formattedActivities as $activity) {
                        $timestamp = $activity['timestamp']; // Already formatted as [DD.MM HH:MM:SS]
                        $message = $activity['message'] ?? 'no message';
                        $event = $activity['event'] ?? 'unknown';

                        $formatted[] = "{$timestamp} {$message} ({$event})";
                    }

                    return implode("\n", $formatted);
                })
                ->help('Timeline of task operations and stage transitions (format: [DD.MM HH:MM:SS])')
                ->hideFromIndex(),
            Text::make('Error')->hideFromIndex(),
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
}
