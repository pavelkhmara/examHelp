<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, Text, Boolean, Select, Code, Number, HasMany};
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Actions\ResearchAction;

class Exam extends Resource
{
    public static $model = \App\Models\Exam::class;
    public static $title = 'title';
    public static $search = ['id','slug','title'];

    public static function label() { return 'Exams'; }
    public static function singularLabel() { return 'Exam'; }
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable()->hideFromIndex(),
            Text::make('ID', 'id')->onlyOnIndex(),
            Text::make('Slug')->rules('required')->sortable(),
            Text::make('Title')->rules('required')->sortable(),
            Select::make('Level')->options([
                'A1'=>'A1','A2'=>'A2','B1'=>'B1','B2'=>'B2','C1'=>'C1','C2'=>'C2',
            ])->displayUsingLabels()->sortable(),
            Boolean::make('Is Active'),
            Text::make('Research Status')->sortable(),
            Number::make('Categories Count')->readonly(),
            Number::make('Examples Count')->readonly(),
            Code::make('Sources')
                ->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $model->$attribute = json_decode($request->$requestAttribute ?: 'null', true);
                })
                ->hideFromIndex(),
            Code::make('Meta')
                ->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $model->$attribute = json_decode($request->$requestAttribute ?: 'null', true);
                })
                ->hideFromIndex(),
            HasMany::make('Categories', 'categories', ExamCategory::class),
            HasMany::make('Examples', 'examples', ExamExampleQuestion::class),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [ new ResearchAction ];
    }

    public function filters(NovaRequest $request)
    {
        return [];
    }
}
