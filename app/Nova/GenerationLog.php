<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, BelongsTo, Text, Code, Number, DateTime};
use Laravel\Nova\Http\Requests\NovaRequest;

class GenerationLog extends Resource
{
    public static $model = \App\Models\GenerationLog::class;
    public static $title = 'id';
    public static $search = ['id','stage'];
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Task', 'task', GenerationTask::class)->searchable(),
            Text::make('Stage')->sortable(),
            Code::make('Request')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->hideFromIndex(),
            Code::make('Response')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->hideFromIndex(),
            Number::make('Prompt Tokens'),
            Number::make('Completion Tokens'),
            Number::make('Total Tokens'),
            DateTime::make('Created At')->sortable(),
        ];
    }
    
    public function filters(NovaRequest $request)
    {
        return [];
    }
}
