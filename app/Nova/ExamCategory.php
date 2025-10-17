<?php

namespace App\Nova;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\{ID, BelongsTo, Text, Number, Code, HasMany};
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Filters\ExamCategoryFilter;
use Laravel\Nova\Panel;
class ExamCategory extends Resource
{
    public static $model = \App\Models\ExamCategory::class;
    public static $title = 'name';
    public static $search = ['id','key','name'];
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            // ID::make()->sortable(),
            // BelongsTo::make('Exam', 'exam', Exam::class)
            //     ->searchable()
            //     ->sortable()
            //     ->readonly(function ($request) {
            //         return !$request->isResourceIndexRequest() && !$request->isResourceDetailRequest();
            //     }),
            // Text::make('Key')->rules('required')->sortable(),
            // Text::make('Name')->rules('required')->sortable(),
            // Text::make('Description')->nullable()->hideFromIndex(),
            // Code::make('Meta')
            // ->json()
            // ->resolveUsing(fn($v)=>is_string($v)?$v:json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
            // ->fillUsing(fn($r,$m,$a,$ra)=>$m->$a=json_decode($r->$ra ?: 'null', true))
            // ->hideFromIndex(),
            // HasMany::make('Examples', 'examples', ExamExampleQuestion::class),

            BelongsTo::make('Exam')->sortable(),
            Text::make('Name')->sortable(),
            Text::make('Key')->hideFromIndex(),
            Number::make('Order')->sortable(),

            // Короткое описание из сервиса (список архетипов)
            Text::make('Description')->onlyOnDetail(),

            new Panel('Category Meta', [
                // Шаги внутри категории с порядком и длительностью
                Code::make('Steps')
                    ->json(JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
                    ->resolveUsing(fn() => json_encode(data_get($this->meta, 'steps', []), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),

                // Краткие сведения по архетипам (id/name/weights/duration)
                Code::make('Archetypes')
                    ->json(JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
                    ->resolveUsing(fn() => json_encode(data_get($this->meta, 'archetypes', []), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),

                // Сводные числа
                Number::make('Sum Weight', fn() => (float) data_get($this->meta, 'sum_weight', 0.0))->onlyOnDetail(),
                Number::make('Archetype Count', fn() => (int) data_get($this->meta, 'archetype_count', 0))->onlyOnDetail(),
            ]),
        ];
    }
    
    public function filters(NovaRequest $request)
    {
        return [
            new ExamCategoryFilter(),
        ];
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        if ($id = $request->get('exam')) {
            return $query->where('exam_id', $id);
        }

        return $query;
    }
}
