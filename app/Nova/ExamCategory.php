<?php

namespace App\Nova;

use App\Nova\Filters\ExamCategoryFilter;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ExamCategory extends Resource
{
    public static $model = \App\Models\ExamCategory::class;

    public static $title = 'name';

    public static $search = ['id', 'key', 'name'];

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

            // Структурированное описание категории (список архетипов)
            Textarea::make('Category Structure Summary')
                ->resolveUsing(function () {
                    return $this->buildCategoryStructureSummary();
                })
                ->readonly()
                ->rows(12)
                ->onlyOnDetail()
                ->help('Human-readable summary of category structure'),

            // Связь с примерами вопросов
            HasMany::make('Example Questions', 'examples', ExamExampleQuestion::class),

            new Panel('Category Meta', [
                // Section archetype - общий шаблон категории
                Code::make('Category Template Data')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(function () {
                        $examStructure = $this->exam->meta['exam_structure'] ?? [];
                        $sectionArchetypes = $examStructure['section_archetypes'] ?? [];

                        // Найдем section_archetype для текущей категории
                        $categoryKey = strtolower($this->key);
                        $sectionArchetype = collect($sectionArchetypes)
                            ->firstWhere('section', $categoryKey);

                        return json_encode($sectionArchetype ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->onlyOnDetail(),

                // Global archetypes для этой категории - детальные данные о типах вопросов
                Code::make('This Category Questions Archetypes')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(function () {
                        $examStructure = $this->exam->meta['exam_structure'] ?? [];
                        $globalArchetypes = $examStructure['global_archetypes'] ?? [];

                        // Найдем все архетипы для текущей категории
                        $categoryKey = strtolower($this->key);
                        $categoryArchetypes = collect($globalArchetypes)
                            ->filter(fn($arc) => strtolower($arc['category'] ?? '') === $categoryKey)
                            ->values()
                            ->all();

                        return json_encode($categoryArchetypes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->onlyOnDetail(),

                // Сводные числа
                // Number::make('Sum Weight', fn () => (float) data_get($this->meta, 'sum_weight', 0.0))->onlyOnDetail(),
                Number::make('Archetype Count', fn () => (int) data_get($this->meta, 'archetype_count', 0))->onlyOnDetail(),
            ]),
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new ExamCategoryFilter,
        ];
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        if ($id = $request->get('exam')) {
            return $query->where('exam_id', $id);
        }

        return $query;
    }

    /**
     * Построить структурированное описание категории
     */
    private function buildCategoryStructureSummary(): string
    {
        $examStructure = $this->exam->meta['exam_structure'] ?? [];
        $globalArchetypes = $examStructure['global_archetypes'] ?? [];

        // Найдем все архетипы для текущей категории
        $categoryKey = strtolower($this->key);
        $categoryArchetypes = collect($globalArchetypes)
            ->filter(fn($arc) => strtolower($arc['category'] ?? '') === $categoryKey)
            ->values()
            ->all();

        if (empty($categoryArchetypes)) {
            return 'No structure information available for this category';
        }

        $lines = [];

        // Header
        $lines[] = "{$this->name}";

        // Duration (sum of all step_duration)
        $totalDuration = 0;
        foreach ($categoryArchetypes as $arc) {
            $totalDuration += $arc['step_duration'] ?? 0;
        }
        if ($totalDuration > 0) {
            $lines[] = "Duration: {$totalDuration} min total";
        }

        // Tasks count
        $taskCount = count($categoryArchetypes);
        $lines[] = "Tasks: {$taskCount}";
        $lines[] = '';

        // Get section number from exam structure
        $sections = $examStructure['sections'] ?? [];
        $sectionNum = 1;
        foreach ($sections as $idx => $section) {
            if (strtolower($section['key'] ?? '') === $categoryKey) {
                $sectionNum = $idx + 1;
                break;
            }
        }

        // List each archetype/task
        foreach ($categoryArchetypes as $idx => $arc) {
            $taskNum = $idx + 1;
            $archetypeId = $arc['id'] ?? 'N/A';
            $archetypeName = $arc['name'] ?? 'Unnamed Task';
            $archetypeDuration = $arc['step_duration'] ?? null;
            $questionType = $arc['question_type'] ?? null;

            // Format like "1.1. Task Name [duration]"
            $taskLine = "{$sectionNum}.{$taskNum}. {$archetypeName}";

            // Add duration in brackets (without type/id for cleaner look)
            if ($archetypeDuration) {
                $taskLine .= " [{$archetypeDuration} min]";
            }

            $lines[] = $taskLine;
        }

        return implode("\n", $lines);
    }
}
