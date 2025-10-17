<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, Text, Boolean, Select, Code, Number, HasMany, KeyValue, Badge};
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Actions\ResearchAction;
use App\Nova\Actions\ImportAiStructure;
use Laravel\Nova\Panel;

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
            Select::make('Research Status', 'research_status')
                ->options([
                    'queued'            => 'queued',
                    'running_overview'  => 'running_overview',
                    'completed'         => 'completed',
                    'failed'            => 'failed',
                ])
                ->displayUsingLabels()
                ->readonly()
                ->sortable(),
            Number::make('Categories Count')->readonly(),
            Number::make('Examples Count')->readonly(),

            // Компактная структура для быстрой навигации
            new Panel('Exam Structure', [
                // Читаемый список секций и шагов
                Code::make('Sections (compact)')
                    ->resolveUsing(function () {
                        $sections = $this->structure_sections ?? [];
                        // оставим по названию и шагам с порядком/длительностью
                        $compact = array_map(function ($s) {
                            return [
                                'name'  => $s['name'] ?? $s['key'] ?? '',
                                'order' => $s['order'] ?? null,
                                'steps' => array_map(fn($st) => [
                                    'name'         => $st['name'] ?? '',
                                    'order'        => $st['order'] ?? null,
                                    'duration_min' => $st['duration_min'] ?? null,
                                ], $s['steps'] ?? []),
                            ];
                        }, $sections);
                        return json_encode($compact, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
                    })
                    ->json()
                    ->onlyOnDetail(),

                // Полный JSON структуры (для отладки/экспорта)
                Code::make('Exam Structure JSON')
                    ->json(JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
                    ->resolveUsing(fn() => json_encode($this->exam_structure ?? (object)[], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),
            ]),
            
            // Статус ресёрча и основные числа
            new Panel('Overview', [
                Badge::make('Research Status', 'research_status')
                ->map([
                    'queued' => 'info',
                    'processing' => 'warning', 
                    'running_overview' => 'warning', 
                    'running' => 'warning',
                    'failed' => 'danger',
                    'error' => 'danger',
                    'completed' => 'success', 
                    'succeeded' => 'success',
                    ])
                    ->labels([
                        'queued'            => 'New',
                        'running_overview'  => 'In Progress',
                        'processing'        => 'Processing',
                        'completed'         => 'Completed',
                        'failed'            => 'Failed',
                        'running'           => 'Running',
                        'error'             => 'Error',
                        'succeeded'         => 'Succeeded',
                    ])
                    ->onlyOnDetail(),

                Number::make('Categories Count', 'categories_count')
                    ->onlyOnDetail(),

                Number::make('Total Exam Duration (min)', 'total_exam_duration')
                    ->onlyOnDetail(),
            ]),

            // Источники: показываем как JSON (читаемо)
            new Panel('Sources', [
                Code::make('Sources JSON')
                    ->json(JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)
                    ->resolveUsing(fn() => json_encode($this->sources ?? [], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))
                    ->onlyOnDetail(),
            ]),

            

            Code::make('Exam Overview')
                ->resolveUsing(function () {
                    $task = $this->generationTasks()->latest()->first();
                    if ($task && $task->result) {
                        $result = $task->result;
                        
                        $overview = [
                            'exam_name' => $result['exam_name'] ?? 'Unknown',
                            'sources_count' => count($result['sources'] ?? []),
                            'archetypes_count' => count($result['archetypes'] ?? []),
                            'categories_covered' => $this->extractCategories($result['archetypes'] ?? [])
                        ];

                        if ($task->error !== null) {
                            $overview['error'] = $task->error;
                        }
                        
                        return json_encode($overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    return 'No research data available';
                })
                ->readonly()
                ->hideFromIndex(),

            // Сводка по секциям/категориям/шагам
            Code::make('Exam Structure')
                ->resolveUsing(function () {
                    $task = $this->generationTasks()->latest()->first();
                    if ($task && $task->result && isset($task->result['archetypes'])) {
                        return $this->generateSectionSummary($task->result['archetypes']);
                    }
                    return 'No section data available';
                })
                ->readonly()
                ->hideFromIndex(),

            // Детальные архетипы
            Code::make('Question Archetypes')
                ->resolveUsing(function () {
                    $task = $this->generationTasks()->latest()->first();
                    if ($task && $task->result && isset($task->result['archetypes'])) {
                        $archetypes = collect($task->result['archetypes'])->map(function ($archetype) {
                            return [
                                'name' => $archetype['name'],
                                'category' => $this->getPrimaryCategory($archetype['category_weights'] ?? ['empty']),
                                'difficulty' => $archetype['difficulty'] ?? 'no difficulty',
                                'description' => $archetype['description'] ?? 'no description'
                            ];
                        })->toArray();
                        
                        return json_encode($archetypes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    return 'No archetype data available';
                })
                ->readonly()
                ->hideFromIndex(),

            // Источники исследования
            Code::make('Research Sources')
                ->resolveUsing(function () {
                    $task = $this->generationTasks()->latest()->first();
                    if ($task && $task->result && isset($task->result['sources'])) {
                        $sources = collect($task->result['sources'])->map(function ($source) {
                            return [
                                'title' => $source['title'],
                                'publisher' => $source['publisher'],
                                'url' => $source['url']
                            ];
                        })->toArray();
                        
                        return json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    return 'No source data available';
                })
                ->readonly()
                ->hideFromIndex(),
                
            // Code::make('Meta')
            //     ->json()
            //     ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
            //     ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
            //         $model->$attribute = json_decode($request->$requestAttribute ?: 'null', true);
            //     })
            //     ->hideFromIndex(),


            HasMany::make('Categories', 'categories', ExamCategory::class),
            HasMany::make('Examples', 'examples', ExamExampleQuestion::class),
            HasMany::make('Generation Tasks', 'generationTasks', GenerationTask::class),
            // HasMany::make('Generation Logs', 'generationLogs', GenerationLog::class),
            // Генерация — чтобы сразу видеть пайплайн
            new Panel('Generation', [
                HasMany::make('Generation Tasks', 'generationTasks', \App\Nova\GenerationTask::class),
                HasMany::make('Generation Logs',  'generationLogs',  \App\Nova\GenerationLog::class),
            ]),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [ 
            new ResearchAction,
            // new ImportAiStructure,
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [];
    }

    /**
     * Вспомогательные методы для обработки данных
     */
    private function generateSectionSummary($archetypes)
    {
        $sections = [
            'Listening' => ['count' => 0, 'difficulties' => []],
            'Reading' => ['count' => 0, 'difficulties' => []],
            'Writing' => ['count' => 0, 'difficulties' => []],
            'Speaking' => ['count' => 0, 'difficulties' => []],
        ];

        foreach ($archetypes as $archetype) {
            $primaryCategory = $this->getPrimaryCategory($archetype['category_weights'] ?? ['empty']);
            if (isset($sections[$primaryCategory])) {
                $sections[$primaryCategory]['count']++;
                $sections[$primaryCategory]['difficulties'][] = $archetype['difficulty'] ?? 'no difficulty';
            }
        }

        $summary = [];
        foreach ($sections as $section => $data) {
            if ($data['count'] > 0) {
                $difficultySummary = array_count_values($data['difficulties']);
                $summary[] = "{$section}: {$data['count']} archetypes (" . 
                            implode(', ', array_map(fn($k, $v) => "$v $k", 
                            array_keys($difficultySummary), $difficultySummary)) . ")";
            }
        }

        return implode("\n", $summary);
    }

    private function getPrimaryCategory($categoryWeights)
    {
        arsort($categoryWeights);
        return array_key_first($categoryWeights);
    }

    private function extractCategories($archetypes)
    {
        $categories = [];
        foreach ($archetypes as $archetype) {
            $primary = $this->getPrimaryCategory($archetype['category_weights'] ?? ['empty']);
            $categories[$primary] = ($categories[$primary] ?? 0) + 1;
        }
        return $categories;
    }
}
