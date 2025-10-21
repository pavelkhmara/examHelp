# Complete Repository Context
Generated: Tue, Oct 21, 2025 10:12:16 AM

## 🧩 File Insight

**Target file:** `app/Services/LanguageApp/ExamResearchService.php`

### Code


```php
<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationTask;
use App\Services\LanguageApp\Validators\JsonSchemaExamOverview;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamResearchService extends AbstractAiService
{
    public function runPipeline(Exam $exam, GenerationTask $task): array
    {
        Log::debug('ExamResearchService: starting pipeline', ['exam_id' => $exam->id, 'task_id' => $task->id]);

        // 1) Overview
        $payload = [
            'exam_slug' => $exam->slug,
            'stage' => 'overview',
            'exam_title' => $exam->title,
            'exam_level' => $exam->level,
            'exam_description' => $exam->description,
            'input' => $task->notes ?? null,
        ];

        $res1 = $this->callAi($payload, ['web' => true]);
        $this->log($task, 'overview', $payload, $res1);

        $exam->update(['research_status' => 'running_overview']);
        $task->update(['result' => $res1['body'] ?? $res1['content'] ?? $res1['raw'] ?? null]); // TODO что используется чаще, из бади и контента вынимаем значения?

        Log::debug('ExamResearchService overview result', ['result' => $res1['content'] ?? null]);

        // return [
        // 'ok'               => true,
        // 'raw'              => $raw,                 // сырое тело HTTP-ответа провайдера
        // 'body'             => $body,                // декодированный top-level JSON провайдера
        // 'content_text'     => $contentText,         // строка JSON внутри message.content
        // 'content'          => $content,             // ДЕКОДИРОВАННЫЙ overview-объект — используем дальше в сервисе
        // 'usage'            => $body['usage'] ?? ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
        // ];

        // RESPONSE validation
        try {
            $validator = new JsonSchemaExamOverview;
            $overview_normalized = $validator->validate($res1['content'] ?? null);
            $this->log($task, 'overview_validated', $payload, ['result' => $overview_normalized]);
        } catch (ValidationException $ve) {
            $task->status = 'failed';
            $task->error = 'Overview JSON validation failed';
            $task->save();

            $this->log($task, 'overview_validation_error', $payload, ['errors' => $ve->errors(), 'body' => $res1['body'] ?? null]);

            return ['ok' => false, 'error' => 'validation_failed', 'errors' => $ve->errors()];
        }

        Log::debug('ExamResearchService overview Validated json', ['overview' => $overview_normalized]);

        // STRICT VALIDATION of tasks (types/payload/scoring)
        /** @var QuestionTypeContract $validator */
        $validator = app(QuestionTypeContract::class);
        $validated = [];
        $tasks = $overview_normalized['tasks'] ?? ($overview_normalized['examples'] ?? []);
        foreach ($tasks as $t) {
            $validated[] = $validator->validateTask($t);
        }
        $overview_normalized['tasks'] = $validated;

        // TODO доходит, записывается?
        if ($res1 && isset($res1['content'])) {
            $exam->update([
                'meta' => array_merge($exam->meta ?? [], [
                    'sources' => $overview_normalized['sources'] ?? $overview_normalized['research_sources'] ?? [],
                    'exam_structure' => $overview_normalized['global_archetypes'],
                    'sections_count' => count($overview_normalized['global_archetypes']),
                    'total_questions' => array_sum(array_column($overview_normalized['global_archetypes'], 'count')),
                    'last_researched_at' => now()->toISOString(),
                ]),
            ]);
        }
        // === 3) Создание/апдейт категорий и шагов внутри категории ===
        // Входной массив архетипов после валидатора: global_archetypes[*]
        $arcs = $overview_normalized['global_archetypes'] ?? $overview_normalized['archetypes'] ?? [];
        $buckets = $this->groupArchetypesByCategory($arcs);
        $this->writeToFile($exam->slug, $exam->level, $buckets, $overview_normalized, $res1['content'] ?? null);

        // Определим порядок категорий:
        //  - если есть overview['category_map'] — возьмём порядок убывания суммарного веса
        //  - иначе — как встретились
        $categoryOrder = $this->rankCategories($overview_normalized, $buckets);

        $createdCategories = [];
        DB::transaction(function () use ($exam, $buckets, $categoryOrder, &$createdCategories) {
            $pos = 1;
            foreach ($categoryOrder as $catKey) {
                $items = $buckets[$catKey] ?? [];
                // slug/key/name
                $key = Str::slug($catKey);
                $name = Str::title($catKey);

                // steps (внутри категории): сортируем по step_order (если есть), иначе по индексу
                $steps = collect($items)
                    ->map(function (array $arc) {
                        // step_order может лежать в "other" от валидатора
                        $stepOrder = null;
                        if (isset($arc['other']) && is_array($arc['other'])) {
                            $maybe = $arc['other']['step_order'] ?? $arc['other']['order'] ?? null;
                            if (is_numeric($maybe)) {
                                $stepOrder = (int) $maybe;
                            }
                        }

                        return [
                            'archetype_id' => $arc['id'],
                            'name' => $arc['name'],
                            'order' => $stepOrder, // может быть null
                            'duration_min' => $arc['step_duration'] ?? null,
                            'difficulty' => $arc['difficulty'] ?? null,
                            'distractors' => $arc['distractors'] ?? [],
                            'stem_templates' => $arc['stem_templates'] ?? [],
                            'ranges' => $arc['ranges'] ?? null,
                            'evidence' => $arc['evidence'] ?? [],
                        ];
                    })
                    ->sortBy(function ($s, $idx) {
                        return is_int($s['order']) ? $s['order'] : (100000 + $idx);
                    })
                    ->values()
                    ->all();

                // метаданные категории (например, суммарный вес архетипов)
                $sumWeight = 0.0;
                foreach ($items as $arc) {
                    foreach (($arc['category_weights'] ?? []) as $cat => $w) {
                        if (Str::lower($cat) === Str::lower($catKey)) {
                            $sumWeight += (float) $w;
                        }
                    }
                }

                $category_model = [
                    'name' => $name,
                    'order' => $pos++,
                    'description' => $this->makeCategoryDescription($items),
                    'meta' => [
                        'source' => 'ai_overview',
                        'raw_category_key' => $catKey,
                        'sum_weight' => $sumWeight,
                        'archetype_count' => count($items),
                        // Храним и шаги, и облегчённые сведения по архетипам
                        'steps' => $steps,
                        'archetypes' => array_map(function ($arc) {
                            return [
                                'id' => $arc['id'],
                                'name' => $arc['name'],
                                'category_weights' => $arc['category_weights'] ?? [],
                                'step_duration' => $arc['step_duration'] ?? null,
                            ];
                        }, $items),
                    ],
                ];

                /** @var ExamCategory $catModel */
                $catModel = ExamCategory::query()->updateOrCreate(['exam_id' => $exam->id, 'key' => $key], $category_model);

                Log::debug('ExamResearchService category model', ['category_model' => $category_model]);

                $createdCategories[] = $catModel->only(['id', 'key', 'name', 'order']);
            }
        });

        $this->log($task, 'categories_persisted', [], [
            'categories' => $createdCategories,
        ]);

        // === 4) Сборка упрощённой «структуры экзамена» ===
        // Для Nova карточки — компактно и понятно: секции (категории) с ordered-steps.
        $structure = $this->buildSimplifiedStructure($overview_normalized, $buckets, $categoryOrder);

        // Можно продублировать структуру в Exam->meta['exam_structure'] (по желанию)
        Log::debug('ExamResearchService add exam-meta-structure', ['exam_structure' => $structure]);

        $meta = $exam->meta ?? [];
        $meta['exam_structure'] = $structure;
        $exam->meta = $meta;
        $exam->categories_count = count($createdCategories);
        $exam->research_status = 'completed';
        $exam->save();

        // === 5) task->result и логи ===
        $task->result = $structure;
        $task->status = 'completed';
        $task->save();

        $this->log($task, 'structure_created', [], ['structure' => $structure]);

        return [
            'ok' => true,
            'overview' => $overview_normalized,
            'structure' => $structure,
            'categories' => $createdCategories,
        ];
    }

    /**
     * Группируем архетипы по category (validator гарантирует ключ).
     * Несуществующую/пустую категорию кладём под 'unknown'.
     */
    protected function groupArchetypesByCategory(array $arcs): array
    {
        $b = [];
        foreach ($arcs as $arc) {
            $cat = trim((string) ($arc['category'] ?? '')) ?: 'unknown';
            $b[$cat] = $b[$cat] ?? [];
            $b[$cat][] = $arc;
        }

        return $b;
    }

    /**
     * Ранжируем категории:
     * - если есть category_map — по убыванию суммарного веса
     * - иначе по порядку появления
     */
    protected function rankCategories(array $overview_normalized, array $buckets): array
    {
        $map = $overview_normalized['category_map'] ?? null;
        if (is_array($map) && $map !== []) {
            $scores = [];
            foreach ($map as $cat => $data) {
                $sum = 0.0;
                foreach (($data['archetype_weights'] ?? []) as $p) {
                    $sum += (float) ($p['weight'] ?? 0);
                }
                $scores[$cat] = $sum;
            }
            // Добавим отсутствующие в карте (если такие есть)
            foreach (array_keys($buckets) as $c) {
                if (! array_key_exists($c, $scores)) {
                    $scores[$c] = 0.0;
                }
            }
            arsort($scores, SORT_NUMERIC);

            return array_keys($scores);
        }

        // fallback — по встречаемости
        return array_keys($buckets);
    }

    protected function makeCategoryDescription(array $items): ?string
    {
        // Мини-описание: перечислим названия архетипов
        $names = array_values(array_unique(array_map(fn ($a) => (string) ($a['name'] ?? ''), $items)));
        if (! $names) {
            return null;
        }

        return 'Tasks: '.implode(', ', $names);
    }

    /**
     * Упрощённая структура экзамена для Nova:
     * [
     *   exam_name, total_exam_duration,
     *   sections: [
     *     { key, name, order, steps: [ {archetype_id,name,order,duration_min}, ... ] },
     *   ]
     * ]
     */
    protected function buildSimplifiedStructure(array $overview_normalized, array $buckets, array $categoryOrder): array
    {
        $sections = [];
        $secPos = 1;

        foreach ($categoryOrder as $catKey) {
            $items = $buckets[$catKey] ?? [];

            // steps с сортировкой по step_order (если есть)
            $steps = collect($items)
                ->map(function (array $arc) {
                    $order = null;
                    if (isset($arc['other']) && is_array($arc['other'])) {
                        $maybe = $arc['other']['step_order'] ?? $arc['other']['order'] ?? null;
                        if (is_numeric($maybe)) {
                            $order = (int) $maybe;
                        }
                    }

                    return [
                        'archetype_id' => $arc['id'],
                        'name' => $arc['name'],
                        'order' => $order,
                        'duration_min' => $arc['step_duration'] ?? null,
                    ];
                })
                ->sortBy(function ($s, $idx) {
                    return is_int($s['order']) ? $s['order'] : (100000 + $idx);
                })
                ->values()
                ->all();

            $sections[] = [
                'key' => Str::slug($catKey),
                'name' => Str::title($catKey),
                'order' => $secPos++,
                'steps' => $steps,
            ];
        }

        return [
            'exam_name' => $overview_normalized['exam_name'] ?? null,
            'total_exam_duration' => $overview_normalized['total_exam_duration'] ?? null,
            'sections' => $sections,
            // оставим источники рядом, удобно выводить в Nova
            'sources' => $overview_normalized['sources'] ?? [],
        ];
    }

    protected function writeToFile(string $examSlug, string $examLevel, array $buckets, array $overview_normalized, mixed $content): void
    {
        try {
            // 1) Готовим имя файла
            $slugRaw = $exam_slug ?? ($exam['slug'] ?? 'exam');   // подстрой под свой контекст
            $levelRaw = $exam_level ?? ($exam['level'] ?? 'level'); // подстрой под свой контекст

            $slug = Str::slug((string) $slugRaw, '_');
            $level = Str::slug((string) $levelRaw, '_');

            $timestamp = Carbon::now()->format('Ymd_His');
            $fileName = "{$slug}_{$level}_{$timestamp}.json";

            // 2) Папка root/files от корня проекта
            $dir = base_path('files');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            // 3) Данные в нужном порядке (buckets → overview_normalized → content['content'])
            $payloadOrdered = [
                'buckets' => $buckets,
                'overview_normalized' => $overview_normalized,
                'content' => $content ?? null,
            ];

            // 4) Сохраняем JSON
            $json = json_encode(
                $payloadOrdered,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;
            file_put_contents($fullPath, $json);

            // (опционально) залогировать успех
            Log::info('Exam research saved', ['path' => $fullPath]);
        } catch (\Throwable $e) {
            // (опционально) залогировать ошибку
            Log::error('Failed to save exam research JSON', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Called by ImportAiStructure after validation
     */
    public function importAiJson(Exam $exam, array $tasks): void
    {
        // Сюда можно вынести создание ExampleQuestion/ExamCategory и т.п.
        // Пока кладём в GenerationTask snapshot для /structure и аудита.
        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'import_validated',
            'status' => 'completed',
            'result' => ['tasks' => $tasks],
        ]);
    }
}

```

### Detected imports / related (PSR-4 App\ → app/)


```txt
App\Models\Exam
App\Models\ExamCategory
App\Models\GenerationTask
App\Services\LanguageApp\Validators\JsonSchemaExamOverview
App\Services\LanguageApp\Validators\QuestionTypeContract

```

```php

#### related: `app/Models/Exam.php`
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug', 'title', 'description', 'level', 'is_active',
        'sources', 'meta', 'research_status',
        'categories_count', 'examples_count',
    ];

    protected $casts = [
        'sources' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(ExamCategory::class, 'exam_id', 'id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    public function generationTasks(): HasMany
    {
        return $this->hasMany(GenerationTask::class, 'exam_id', 'id');
    }

    public function generationLogs(): HasMany
    {
        return $this->hasMany(GenerationLog::class);
    }

    public function loadAllCounts()
    {
        return $this->loadCount([
            'categories',
            'examples',
            'generationTasks',
            'generationLogs',
        ]);
    }


#### related: `app/Models/ExamCategory.php`
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamCategory extends Model
{
    protected $fillable = ['exam_id', 'key', 'name', 'meta', 'description', 'order'];

    protected $casts = ['meta' => 'array'];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function examples()
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }
}

#### related: `app/Models/GenerationTask.php`
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GenerationTask extends Model
{
    protected $fillable = ['exam_id', 'type', 'status', 'request', 'response', 'error', 'attempts', 'result'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'result' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function logs()
    {
        return $this->hasMany(GenerationLog::class);
    }
}

#### related: `app/Services/LanguageApp/Validators/JsonSchemaExamOverview.php`
<?php

namespace App\Services\LanguageApp\Validators;

use Illuminate\Validation\ValidationException;

/**
 * JsonSchemaExamOverview — гибкая валидация и нормализация Exam Overview.
 *
 * Итоговая СХЕМА (выход):
 * [
 *   "exam_name" => "string",
 *   "sources" => [
 *     ["url"=>"string","title"=>"string","publisher"=>"string"], ...
 *   ],
 *   "global_archetypes" => [
 *     [
 *       "id" => "string",
 *       "name" => "string",
 *       "category" => "string",                 // primary category (по максимальному весу или явному полю)
 *       "category_weights" => {string: float},  // нормализованные ключи (lowercase)
 *       "step_duration" => ?int,                // минуты; из typical_length_or_time / ranges / null
 *
 *       // агрегированные логические блоки
 *       "stem_templates" => ["string", ...],    // инструкции/шаблоны/частые фразы
 *       "evidence" => ["mixed", ...],           // ссылки/индексы/строки
 *       "distractors" => ["string", ...],       // typical_distractors/...
 *       "ranges" => mixed,                      // numeric_ranges|numeric_ranges_and_constraints|typical_*_range|...
 *       "difficulty" => "string|null",          // difficulty|difficulty_band*
 *
 *       // прочие, редкие поля — без валидации, как есть
 *       "other" => { ... }                      // все нераспознанные ключи архетипа
 *     ],
 *     ...
 *   ],
 *   "category_map" => [
 *     "<category_name>" => [
 *        "archetype_weights" => [
 *          ["archetype_id"=>"string","weight"=>float], ...
 *        ],
 *     ],
 *   ],
 *   "total_exam_duration" => ?int,              // сумма известных step_duration
 *   "rationale" => "string|null"
 * ]
 */
final class JsonSchemaExamOverview
{
    public function validate(mixed $data): array
    {
        if (! is_array($data) || ! $this->isAssoc($data)) {
            throw ValidationException::withMessages(['root' => 'overview must be a JSON object']);
        }

        // --- exam_name
        $examName = $this->mustString($data, 'exam_name');

        // --- sources: массив объектов с url,title,publisher (строки)
        $sources = $this->normalizeSources($data['sources'] ?? null);


#### related: `app/Services/LanguageApp/Validators/QuestionTypeContract.php`
<?php

namespace App\Services\LanguageApp\Validators;

use App\Domain\Scoring\Adapters\ExactScoringAdapter;
use App\Domain\Scoring\Adapters\FuzzyScoringAdapter;
use App\Domain\Scoring\Adapters\PartialScoringAdapter;
use App\Domain\Scoring\Adapters\RegexScoringAdapter;
use App\Domain\Scoring\Adapters\RubricScoringAdapter;
use App\Domain\Taxonomy\QuestionType;
use Illuminate\Validation\ValidationException;

final class QuestionTypeContract
{
    /** Allowed scoring modes per question type (from docs/questions_types.md) */
    private const ALLOWED = [
        'single_select' => ['exact'],
        'multi_select' => ['exact', 'partial'],
        'true_false' => ['exact'],
        'yes_no_ng' => ['exact'],
        'dropdown_cloze' => ['exact'],
        'gap_cloze' => ['fuzzy', 'regex', 'exact'],
        'banked_cloze' => ['exact'],
        'matching' => ['exact', 'partial'],
        'order_sentences' => ['exact', 'partial'],
        'order_words' => ['exact', 'partial'],
        'highlight_text' => ['exact', 'partial'],
        'short_answer' => ['fuzzy', 'regex', 'exact'],
        'numeric' => ['exact'],
        'listen_mcq' => ['exact'],
        'dictation' => ['fuzzy', 'regex'],
        'error_correction' => ['rubric', 'fuzzy', 'regex'],
        'writing_prompt' => ['rubric'],
        'speaking_prompt' => ['rubric'],
    ];

    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            'exact' => new ExactScoringAdapter,
            'partial' => new PartialScoringAdapter,
            'fuzzy' => new FuzzyScoringAdapter,
            'regex' => new RegexScoringAdapter,
            'rubric' => new RubricScoringAdapter,
        ];
    }

    /** Validate one task block: {type, items[], scoring{mode,...}} */
    public function validateTask(array $task): array
    {
        $type = (string) ($task['type'] ?? '');
        if (! in_array($type, QuestionType::all(), true)) {
            throw ValidationException::withMessages([
                'type' => "Unknown question type: '{$type}'. Allowed: ".implode(',', QuestionType::all()),
            ]);
        }

        $items = $task['items'] ?? null;

```

### Views referenced (view('x.y'))


```txt

```
