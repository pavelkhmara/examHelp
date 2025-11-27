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

/**
 * @property string $key
 * @property string $name
 * @property \App\Models\Exam $exam
 */
class ExamCategory extends Resource
{
    /**
     * @var class-string<\App\Models\ExamCategory>
     */
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

            new Panel('Category Meta (V2 Structure)', [
                // Section data - supports both structure_v2 and exam_structure formats
                Code::make('Section Structure')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(function () {
                        $currentSection = $this->findCurrentSection();

                        return json_encode($currentSection ?? ['error' => 'Section not found'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->onlyOnDetail()
                    ->help('Section structure (supports both structure_v2 and exam_structure formats)'),

                // Section archetype/metadata
                Code::make('Section Archetype / Metadata')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(function () {
                        $structureV2 = $this->exam->meta['structure_v2'] ?? null;
                        $examStructure = $this->exam->meta['exam_structure'] ?? null;

                        // For structure_v2: extract section metadata (not assembly)
                        if ($structureV2) {
                            $currentSection = $this->findCurrentSection();
                            if ($currentSection) {
                                // Extract section-level metadata (exclude assembly to avoid duplication)
                                $metadata = array_filter($currentSection, function ($key) {
                                    return ! in_array($key, ['assembly', 'question_archetypes']); // Exclude these as they're shown separately
                                }, ARRAY_FILTER_USE_KEY);

                                return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            }

                            return json_encode(['error' => 'Section not found'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        }

                        // For exam_structure: look for section_archetypes
                        $sectionArchetypes = $examStructure['section_archetypes'] ?? [];

                        if (empty($sectionArchetypes)) {
                            return json_encode(['info' => 'No section_archetypes in this exam'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        }

                        // Найдем section_archetype для текущей категории
                        $categoryKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $this->key));
                        $sectionArchetype = null;

                        foreach ($sectionArchetypes as $sa) {
                            $saKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $sa['section'] ?? ''));
                            if ($saKey === $categoryKey) {
                                $sectionArchetype = $sa;
                                break;
                            }
                        }

                        return json_encode($sectionArchetype ?? ['error' => 'Section archetype not found'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->onlyOnDetail()
                    ->help('Section-level metadata: skill, weight, duration, phases, navigation, scoring, etc.'),

                // Question archetypes (question templates)
                Code::make('This Section Question Archetypes')
                    ->json(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    ->resolveUsing(function () {
                        $structureV2 = $this->exam->meta['structure_v2'] ?? null;
                        $examStructure = $this->exam->meta['exam_structure'] ?? null;

                        // For structure_v2: get question_archetypes from section
                        if ($structureV2) {
                            $currentSection = $this->findCurrentSection();
                            if ($currentSection && isset($currentSection['question_archetypes'])) {
                                return json_encode($currentSection['question_archetypes'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            }

                            return json_encode(['info' => 'No question_archetypes defined for this section'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        }

                        // For exam_structure: get question_archetypes
                        $questionArchetypes = $examStructure['question_archetypes'] ?? [];

                        if (empty($questionArchetypes)) {
                            return json_encode(['info' => 'No question_archetypes in this exam'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        }

                        $currentSection = $this->findCurrentSection();

                        if (! $currentSection || empty($currentSection['steps'])) {
                            return json_encode(['error' => 'Section not found'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        }

                        // Собираем детальные данные архетипов для этой секции
                        $archetypeIds = collect($currentSection['steps'])->pluck('archetype_id')->filter()->all();
                        $detailedArchetypes = collect($questionArchetypes)
                            ->filter(fn (array $arc) => in_array($arc['id'] ?? null, $archetypeIds))
                            ->values()
                            ->all();

                        return json_encode($detailedArchetypes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    })
                    ->onlyOnDetail()
                    ->help('Question archetypes with type, config, scoring, duration. For V2: question_archetypes from section. For V1: question_archetypes filtered by section.'),

                // Сводные числа
                Number::make('Items/Steps Count', function () {
                    $currentSection = $this->findCurrentSection();

                    if (! $currentSection) {
                        return 0;
                    }

                    $assembly = $currentSection['assembly'] ?? [];
                    $mode = $assembly['mode'] ?? null;

                    // For structure_v2: count based on mode
                    if ($mode === 'blueprint' && isset($assembly['blueprint'])) {
                        return array_sum(array_column($assembly['blueprint'], 'pick'));
                    } elseif ($mode === 'pool' && isset($assembly['pick'])) {
                        return $assembly['pick'];
                    } elseif ($mode === 'inline') {
                        // For inline mode, tasks are embedded (count would need task array)
                        return 0; // or count tasks if available
                    }

                    // For exam_structure: count steps
                    return count($currentSection['steps'] ?? []);
                })->onlyOnDetail(),
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
     * Построить структурированное описание категории (V2 structure)
     */
    private function buildCategoryStructureSummary(): string
    {
        // Try structure_v2 first (newer format), then exam_structure (older format)
        $structureV2 = $this->exam->meta['structure_v2'] ?? null;
        $examStructure = $this->exam->meta['exam_structure'] ?? null;

        if ($structureV2) {
            return $this->buildCategorySummaryFromStructureV2($structureV2);
        } elseif ($examStructure) {
            return $this->buildCategorySummaryFromExamStructure($examStructure);
        }

        return 'No structure information available for this category';
    }

    /**
     * Build summary from structure_v2 format (sections[].id, assembly.blueprint/pool/inline)
     */
    private function buildCategorySummaryFromStructureV2(array $structureV2): string
    {
        $sections = $structureV2['sections'] ?? [];

        // Normalize category key for matching
        $categoryKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $this->key));
        $currentSection = null;
        $sectionNum = 1;

        foreach ($sections as $idx => $section) {
            $sectionId = strtolower(str_replace(['/', ' ', '_', '-'], '', $section['id'] ?? ''));
            if ($sectionId === $categoryKey) {
                $currentSection = $section;
                $sectionNum = $idx + 1;
                break;
            }
        }

        if (! $currentSection) {
            return 'No structure information available for this category';
        }

        $lines = [];
        $lines[] = $currentSection['title'] ?? $this->name;

        // Weight (if available)
        if (isset($currentSection['weight'])) {
            $weight = round($currentSection['weight'] * 100);
            $lines[] = "Weight: {$weight}%";
        }

        $assembly = $currentSection['assembly'] ?? [];
        $mode = $assembly['mode'] ?? 'unknown';
        $lines[] = "Assembly mode: {$mode}";

        // Handle different assembly modes
        if ($mode === 'blueprint') {
            $blueprint = $assembly['blueprint'] ?? [];
            if (! empty($blueprint)) {
                $totalItems = array_sum(array_column($blueprint, 'pick'));
                $lines[] = "Total items: {$totalItems}";
                $lines[] = 'Slots: '.count($blueprint);
                $lines[] = '';

                // List blueprint slots
                foreach ($blueprint as $idx => $slot) {
                    $slotNum = $idx + 1;
                    $slotName = $slot['slot'] ?? 'Unnamed Slot';
                    $pick = $slot['pick'] ?? 0;
                    $slotWeight = isset($slot['weight']) ? round($slot['weight'] * 100).'%' : 'N/A';

                    $lines[] = "{$sectionNum}.{$slotNum}. {$slotName} (pick: {$pick}, weight: {$slotWeight})";
                }
            } else {
                $lines[] = 'No blueprint slots defined';
            }
        } elseif ($mode === 'pool') {
            $pick = $assembly['pick'] ?? 0;
            $poolId = $assembly['pool_id'] ?? 'unknown';
            $lines[] = "Pick from pool: {$pick} items";
            $lines[] = "Pool: {$poolId}";

            // Show filters if available
            $filters = $assembly['filters'] ?? [];
            if (! empty($filters)) {
                $lines[] = '';
                $lines[] = 'Filters:';
                if (isset($filters['type'])) {
                    $lines[] = '  - Types: '.implode(', ', $filters['type']);
                }
                if (isset($filters['difficulty'])) {
                    $lines[] = '  - Difficulty: '.implode(', ', $filters['difficulty']);
                }
                if (isset($filters['cefr'])) {
                    $lines[] = '  - CEFR: '.implode(', ', $filters['cefr']);
                }
            }
        } elseif ($mode === 'inline') {
            $lines[] = 'Tasks defined inline (see section data for details)';
        } else {
            $lines[] = 'Unknown assembly mode';
        }

        return implode("\n", $lines);
    }

    /**
     * Build summary from exam_structure format (sections[].key, steps)
     */
    private function buildCategorySummaryFromExamStructure(array $examStructure): string
    {
        $sections = $examStructure['sections'] ?? [];

        // Normalize category key for matching
        $categoryKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $this->key));
        $currentSection = null;
        $sectionNum = 1;

        foreach ($sections as $idx => $section) {
            $sectionKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $section['key'] ?? ''));
            if ($sectionKey === $categoryKey) {
                $currentSection = $section;
                $sectionNum = $section['order'] ?? ($idx + 1);
                break;
            }
        }

        if (! $currentSection || empty($currentSection['steps'])) {
            return 'No structure information available for this category';
        }

        $lines = [];
        $lines[] = "{$this->name}";

        // Duration (sum of all step durations)
        $totalDuration = 0;
        foreach ($currentSection['steps'] as $step) {
            $totalDuration += $step['duration_min'] ?? 0;
        }
        if ($totalDuration > 0) {
            $lines[] = "Duration: {$totalDuration} min total";
        }

        // Tasks count
        $taskCount = count($currentSection['steps']);
        $lines[] = "Tasks: {$taskCount}";
        $lines[] = '';

        // List each step/task
        foreach ($currentSection['steps'] as $idx => $step) {
            $taskNum = $idx + 1;
            $stepName = $step['name'] ?? 'Unnamed Task';
            $stepDuration = $step['duration_min'] ?? null;
            $archetypeId = $step['archetype_id'] ?? 'N/A';

            // Format like "1.1. Task Name [duration]"
            $taskLine = "{$sectionNum}.{$taskNum}. {$stepName}";

            // Add duration in brackets
            if ($stepDuration) {
                $taskLine .= " [{$stepDuration} min]";
            }

            $lines[] = $taskLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Find current section in either structure_v2 or exam_structure format
     */
    private function findCurrentSection(): ?array
    {
        $structureV2 = $this->exam->meta['structure_v2'] ?? null;
        $examStructure = $this->exam->meta['exam_structure'] ?? null;

        $categoryKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $this->key));

        // Try structure_v2 first
        if ($structureV2) {
            foreach ($structureV2['sections'] ?? [] as $section) {
                $sectionId = strtolower(str_replace(['/', ' ', '_', '-'], '', $section['id'] ?? ''));
                if ($sectionId === $categoryKey) {
                    return $section;
                }
            }
        }

        // Fallback to exam_structure
        if ($examStructure) {
            foreach ($examStructure['sections'] ?? [] as $section) {
                $sectionKey = strtolower(str_replace(['/', ' ', '_', '-'], '', $section['key'] ?? ''));
                if ($sectionKey === $categoryKey) {
                    return $section;
                }
            }
        }

        return null;
    }
}
