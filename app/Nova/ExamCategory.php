<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, BelongsTo, Text, Code, HasMany};
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Filters\Filter;

class ExamCategory extends Resource
{
    public static $model = \App\Models\ExamCategory::class;
    public static $title = 'name';
    public static $search = ['id','key','name'];
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return !$request->isResourceIndexRequest() && !$request->isResourceDetailRequest();
                }),
            Text::make('Key')->rules('required')->sortable(),
            Text::make('Name')->rules('required')->sortable(),
            Text::make('Description')->nullable()->hideFromIndex(),
            Code::make('Meta')
                ->json()
                ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
                ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra ?: 'null', true))
                ->hideFromIndex(),
            HasMany::make('Examples', 'examples', ExamExampleQuestion::class),
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
