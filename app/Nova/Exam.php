<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, Text, Boolean, Select, Code, Number, HasMany};
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Actions\ResearchAction;
use App\Nova\Actions\ImportAiStructure;

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

            Code::make('Meta')
                ->json()
                ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $model->$attribute = json_decode($request->$requestAttribute ?: 'null', true);
                })
                ->hideFromIndex(),

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
                        
                        return json_encode($overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                    return 'No research data available';
                })
                ->readonly()
                ->hideFromIndex(),

            // Сводка по секциям
            Code::make('Section Summary')
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
                                'category' => $this->getPrimaryCategory($archetype['category_weights']),
                                'difficulty' => $archetype['difficulty'],
                                'description' => $archetype['description']
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

            HasMany::make('Categories', 'categories', ExamCategory::class),
            HasMany::make('Examples', 'examples', ExamExampleQuestion::class),
            HasMany::make('Generation Tasks', 'generationTasks', GenerationTask::class),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [ new ResearchAction, new ImportAiStructure];
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
            $primaryCategory = $this->getPrimaryCategory($archetype['category_weights']);
            if (isset($sections[$primaryCategory])) {
                $sections[$primaryCategory]['count']++;
                $sections[$primaryCategory]['difficulties'][] = $archetype['difficulty'];
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
            $primary = $this->getPrimaryCategory($archetype['category_weights']);
            $categories[$primary] = ($categories[$primary] ?? 0) + 1;
        }
        return $categories;
    }
}
