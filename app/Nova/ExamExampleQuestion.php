<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, BelongsTo, Text, Code};
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Filters\ExamFilter;

class ExamExampleQuestion extends Resource
{
    public static $model = \App\Models\ExamExampleQuestion::class;
    public static $title = 'question';
    public static $search = ['id','question'];
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
        BelongsTo::make('Category', 'category', ExamCategory::class)
            ->searchable()
            ->nullable(),
        Text::make('Question')->rules('required')->onlyOnForms(),
        Text::make('Question Preview', 'question')->exceptOnForms()->onlyOnDetail(),
        Code::make('Good Answer')->json()
          ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
          ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra?:'null',true))
          ->hideFromIndex(),
            Code::make('Average Answer')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra?:'null',true))
              ->hideFromIndex(),
            Code::make('Bad Answer')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra?:'null',true))
              ->hideFromIndex(),
            Code::make('Rubric Breakdown')->json()
              ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
              ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra?:'null',true))
              ->hideFromIndex(),
        ];
    }
    
    public function filters(NovaRequest $request)
    {
      return [
          new ExamFilter,
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
