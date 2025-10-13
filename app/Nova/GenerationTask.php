<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, Text, Select, Code, Number, DateTime};
use Laravel\Nova\Http\Requests\NovaRequest;

class GenerationTask extends Resource
{
    public static $model = \App\Models\GenerationTask::class;
    public static $title = 'id';
    public static $search = ['id','type','status'];
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            Text::make('Type')->sortable(),
            Select::make('Status')->options([
                'queued'=>'queued','running'=>'running','completed'=>'completed','failed'=>'failed',
            ])->displayUsingLabels()->sortable(),
            Number::make('Attempts')->sortable(),
            Code::make('Request')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->hideFromIndex(),
            Code::make('Response')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->hideFromIndex(),
            Text::make('Error')->hideFromIndex(),
            DateTime::make('Created At')->sortable(),
        ];
    }
    
    public function filters(NovaRequest $request)
    {
        return [];
    }
}
