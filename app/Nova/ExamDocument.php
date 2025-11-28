<?php

namespace App\Nova;

use App\Nova\Exam as ExamResource;
use App\Nova\GenerationTask as GenerationTaskResource;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * @extends Resource<\App\Models\ExamDocument>
 */
class ExamDocument extends Resource
{
    public static string $model = \App\Models\ExamDocument::class;

    public static $title = 'original_name';

    public static $search = ['id', 'original_name', 'mime'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            Text::make('ID', 'id')->readonly()->copyable()->sortable(),
            BelongsTo::make('Exam', 'exam', ExamResource::class)->searchable()->sortable(),
            Text::make('Original Name', 'original_name')->sortable(),
            Text::make('Mime', 'mime')->sortable(),
            Number::make('Size (bytes)', 'size')->sortable(),
            Text::make('Status', 'status')->sortable(),
            Text::make('Error', 'error')->hideFromIndex(),
            Code::make('Extracted Text', 'extracted_text')->hideFromIndex(),
            Code::make('Meta', 'meta')->json()->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->hideFromIndex(),
            DateTime::make('Created', 'created_at')->onlyOnDetail(),
            DateTime::make('Updated', 'updated_at')->onlyOnDetail(),
            BelongsTo::make('Generation Task', 'generationTask', GenerationTaskResource::class)
                ->nullable()
                ->exceptOnForms(),
        ];
    }
}
