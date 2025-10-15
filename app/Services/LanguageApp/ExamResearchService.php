<?php

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Log;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamExampleQuestion;
use App\Models\GenerationTask;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ExamResearchService extends AbstractAiService
{
    public function runPipeline(Exam $exam, GenerationTask $task): array
    {
        Log::debug('ExamResearchService: starting pipeline', ['exam_id' => $exam->id, 'task_id' => $task->id]);

        // 1) Overview
        $req1 = [
            'exam_slug' => $exam->slug,
            'stage' => 'overview',
            'user_input' => "Research exam structure for: {$exam->title}. Description: {$exam->description}"
        ];
        
        $res1 = $this->callAi($req1, [ 'web' => true ]);
        $this->log($task, 'overview', $req1, $res1);
        
        $exam->update(['research_status' => 'running_overview']);
        $task->update(['result' => $res1['content'] ?? null]);
        
        Log::debug('ExamResearchService overview result', ['result' => $res1['content'] ?? null]);

        // return [
        //     'ok'      => true,
        //     'json'    => $raw,
        //     'usage'   => $data['usage'] ?? ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
        //     'data'    => $data,
        //     'content' => $json,
        //     'content_json' => $content,
        // ];

        if ($res1 && isset($res1['content_json'])) {
            $exam->update([
                'meta' => array_merge($exam->meta ?? [], [
                    'exam_structure' => $res1['content_json']['archetypes'],
                    'sections_count' => count($res1['content_json']['archetypes']),
                    'total_questions' => array_sum(array_column($res1['content_json']['archetypes'], 'count')),
                    'last_researched_at' => now()->toISOString(),
                ])
            ]);
        }
        // 2) Categories
        if ($res1['ok'] && !empty($res1['content'])) {
            $this->parseAndCreateCategories($exam, $res1['content'], $task);
        }
        
        $exam->update(['research_status' => 'completed']);
        $task->update(['status' => 'completed', 'result' => $res1['content'] ?? null]);
        
        return $res1;
    }

    
    /**
     * Парсит ответ AI и создает категории экзамена
     */
    private function parseAndCreateCategories(Exam $exam, array $aiResponse, GenerationTask $task): void
    {
        Log::debug('Parsing AI response for categories', ['exam_id' => $exam->id]);
        
        try {
            $createdCount = 0;
            $updatedCount = 0;
            
            // Вариант 1: Если категории в корне
            if (isset($aiResponse['categories']) && is_array($aiResponse['categories'])) {
                $createdCount = $this->processCategoriesArray($exam, $aiResponse['categories']);
            }
            // Вариант 2: Если категории в sections
            elseif (isset($aiResponse['sections']) && is_array($aiResponse['sections'])) {
                $createdCount = $this->processSectionsArray($exam, $aiResponse['sections']);
            }
            // Вариант 3: Если структура другая
            else {
                $createdCount = $this->processOtherStructures($exam, $aiResponse);
            }
            
            // Обновляем счетчик категорий
            $exam->update(['categories_count' => $exam->categories()->count()]);
            
            Log::info('Categories processing completed', [
                'exam_id' => $exam->id,
                'created' => $createdCount,
                'updated' => $updatedCount,
                'total' => $exam->categories_count
            ]);
            
            // Логируем результат
            $this->log($task, 'categories_parsed', [
                'categories_created' => $createdCount,
                'categories_updated' => $updatedCount
            ], ['success' => true]);
            
        } catch (\Exception $e) {
            Log::error('Failed to parse categories from AI response', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage(),
                'ai_response' => $aiResponse
            ]);
            
            $this->log($task, 'categories_error', [], ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Обрабатывает массив categories из AI response
     */
    private function processCategoriesArray(Exam $exam, array $categories): int
    {
        $createdCount = 0;
        
        foreach ($categories as $categoryData) {
            // Валидация обязательных полей
            if (empty($categoryData['key']) || empty($categoryData['name'])) {
                Log::warning('Skipping category with missing key/name', ['data' => $categoryData]);
                continue;
            }
            
            // Создаем или обновляем категорию
            $category = ExamCategory::updateOrCreate(
                [
                    'exam_id' => $exam->id,
                    'key' => $this->normalizeKey($categoryData['key'])
                ],
                [
                    'name' => $categoryData['name'],
                    'description' => $categoryData['description'] ?? null,
                    'meta' => $this->buildCategoryMeta($categoryData),
                    'order' => $categoryData['order'] ?? ($createdCount + 1)
                ]
            );
            
            if ($category->wasRecentlyCreated) {
                $createdCount++;
                Log::debug('Category created', [
                    'exam_id' => $exam->id,
                    'category_key' => $category->key,
                    'category_name' => $category->name
                ]);
            }
        }
        
        return $createdCount;
    }

    /**
     * Обрабатывает массив sections (альтернативная структура)
     */
    private function processSectionsArray(Exam $exam, array $sections): int
    {
        $createdCount = 0;
        
        foreach ($sections as $index => $sectionData) {
            // Генерируем key из name если нет key
            $key = $sectionData['key'] ?? $this->generateKeyFromName($sectionData['name'] ?? "section_{$index}");
            
            $category = ExamCategory::updateOrCreate(
                [
                    'exam_id' => $exam->id,
                    'key' => $key
                ],
                [
                    'name' => $sectionData['name'] ?? $sectionData['title'] ?? "Section " . ($index + 1),
                    'description' => $sectionData['description'] ?? null,
                    'meta' => array_merge(
                        $this->buildCategoryMeta($sectionData),
                        ['original_structure' => 'sections']
                    ),
                    'order' => $sectionData['order'] ?? $index
                ]
            );
            
            if ($category->wasRecentlyCreated) {
                $createdCount++;
            }
        }
        
        return $createdCount;
    }

    /**
     * Обрабатывает другие возможные структуры
     */
    private function processOtherStructures(Exam $exam, array $aiResponse): int
    {
        $createdCount = 0;
        
        // Пытаемся найти категории в разных возможных местах
        $possibleCategoryPaths = [
            'modules', 'parts', 'components', 'categories', 'sections'
        ];
        
        foreach ($possibleCategoryPaths as $path) {
            if (isset($aiResponse[$path]) && is_array($aiResponse[$path])) {
                $createdCount += $this->processCategoriesArray($exam, $aiResponse[$path]);
                break; // Останавливаемся на первой найденной структуре
            }
        }
        
        // Если ничего не найдено, создаем базовые категории
        if ($createdCount === 0) {
            $createdCount = $this->createFallbackCategories($exam, $aiResponse);
        }
        
        return $createdCount;
    }

    /**
     * Создает fallback категории если AI не вернул структуру
     */
    private function createFallbackCategories(Exam $exam, array $aiResponse): int
    {
        Log::warning('No categories found in AI response, creating fallback categories', [
            'exam_id' => $exam->id,
            'response_keys' => array_keys($aiResponse)
        ]);
        
        $fallbackCategories = [
            ['key' => 'reading', 'name' => 'Reading', 'description' => 'Reading comprehension section'],
            ['key' => 'writing', 'name' => 'Writing', 'description' => 'Writing tasks section'],
            ['key' => 'listening', 'name' => 'Listening', 'description' => 'Listening comprehension section'],
            ['key' => 'speaking', 'name' => 'Speaking', 'description' => 'Speaking assessment section'],
        ];
        
        return $this->processCategoriesArray($exam, $fallbackCategories);
    }

    /**
     * Нормализует key категории
     */
    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($key)));
    }

    /**
     * Генерирует key из name
     */
    private function generateKeyFromName(string $name): string
    {
        return $this->normalizeKey($name);
    }

    /**
     * Строит meta данные для категории
     */
    private function buildCategoryMeta(array $categoryData): array
    {
        $meta = [
            'source' => 'ai_research',
            'parsed_at' => now()->toISOString(),
        ];
        
        // Добавляем дополнительные данные из AI response
        $additionalFields = [
            'question_count', 'time_minutes', 'weight', 'difficulty',
            'question_types', 'skills_tested', 'scoring_range'
        ];
        
        foreach ($additionalFields as $field) {
            if (isset($categoryData[$field])) {
                $meta[$field] = $categoryData[$field];
            }
        }
        
        return $meta;
    }

    // private function convertArchetypesToStructure($archetypes)
    // {
    //     $sections = [];
        
    //     foreach ($archetypes as $archetype) {
    //         $category = $this->getPrimaryCategory($archetype['category_weights']);
            
    //         if (!isset($sections[$category])) {
    //             $sections[$category] = [
    //                 'key' => strtolower($category),
    //                 'title' => $category,
    //                 'count' => 0,
    //                 'archetypes' => [],
    //                 'difficulty_breakdown' => []
    //             ];
    //         }
            
    //         $sections[$category]['count']++;
    //         $sections[$category]['archetypes'][] = $archetype['name'];
    //         $sections[$category]['difficulty_breakdown'][$archetype['difficulty']] = 
    //             ($sections[$category]['difficulty_breakdown'][$archetype['difficulty']] ?? 0) + 1;
    //     }
        
    //     // Преобразуем в массив для JSON
    //     $sectionArray = [];
    //     foreach ($sections as $section) {
    //         $sectionArray[] = $section;
    //     }
        
    //     return [
    //         'sections' => $sectionArray,
    //         'total_archetypes' => count($archetypes),
    //         'generated_at' => now()->toISOString()
    //     ];
    // }

    public function importAiJson(Exam $exam, array $ai): void
    {
        // Безопасная транзакция: и exam, и categories
        DB::transaction(function () use ($exam, $ai) {
            // 1) Источники
            $sources = Arr::get($ai, 'sources', []);
            // Нормализуем простой вид: [ {url, title, publisher}, ... ]
            $exam->sources = array_map(function ($s) {
                return [
                    'url'       => Arr::get($s, 'url'),
                    'title'     => Arr::get($s, 'title'),
                    'publisher' => Arr::get($s, 'publisher'),
                ];
            }, is_array($sources) ? $sources : []);

            // 2) Мета
            $meta = [
                'exam_name'  => Arr::get($ai, 'exam_name'),
                'timebox_minutes' => Arr::get($ai, 'timebox_minutes'),
                'conflicts_and_rationale' => Arr::get($ai, 'conflicts_and_rationale'),
                'assumptions_and_limits'  => Arr::get($ai, 'assumptions_and_limits'),
            ];

            // Подстрахуемся: если title пустой — используем exam_name
            if (empty($exam->title) && !empty($meta['exam_name'])) {
                $exam->title = Str::of($meta['exam_name'])->upper()->toString();
            }
            if (empty($exam->slug) && !empty($meta['exam_name'])) {
                $exam->slug = Str::slug($meta['exam_name']);
            }

            $exam->meta = $meta;

            // 3) Категории из archetypes
            $archetypes = Arr::get($ai, 'archetypes', []);
            $order = 0;

            foreach ($archetypes as $arc) {
                $key  = Arr::get($arc, 'id') ?? Str::uuid()->toString();
                $name = Arr::get($arc, 'name', $key);

                // Описание категории: коротко из description
                $description = Arr::get($arc, 'description');

                // Соберём meta категории
                $catMeta = [
                    'category_weights'  => Arr::get($arc, 'category_weights', []),
                    'typical_distractors' => Arr::get($arc, 'typical_distractors', []),
                    'verbs'            => Arr::get($arc, 'verbs', []),
                    'numeric_ranges'   => Arr::get($arc, 'numeric_ranges', []),
                    'units'            => Arr::get($arc, 'units', []),
                    'common_visuals'   => Arr::get($arc, 'common_visuals', []),
                    'difficulty'       => Arr::get($arc, 'difficulty'),
                    'evidence_idx'     => Arr::get($arc, 'evidence', []), // индексы в sources
                    'rationale'        => Arr::get($arc, 'rationale'),
                ];

                /** @var ExamCategory $cat */
                $cat = ExamCategory::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'key' => $key],
                    ['name' => $name]
                );

                $cat->description = $description;
                $cat->order = $order++;
                $cat->meta = $catMeta;
                $cat->save();
            }

            // 4) Счётчики
            $exam->categories_count = $exam->categories()->count();
            // examples_count пока не трогаем — будет другой импорт
            $exam->save();
        });
    }
}
