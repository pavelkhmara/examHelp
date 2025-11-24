<?php

namespace App\Services\LanguageApp\Prompts;

/**
 * Phase A: Exam Skeleton Generation
 *
 * Generates the structural skeleton of the exam without individual question details.
 * Output conforms to s2_exam_json_archetype_v2.json schema.
 */
class PromptOverviewPhaseA
{
    /**
     * Build the Phase A prompt for exam skeleton generation
     */
    public static function build(
        string $examTitle,
        string $userInput,
        string $contextNotes,
        ?string $retryHint = null,
        ?array $userInputParsed = null,
        string $documentsHint = ''
    ): string {
        $documentPriorityHint = self::getDocumentPriorityHint($documentsHint);
        $localizationHint = self::getLocalizationHint($userInputParsed);
        $schemaDescription = self::getSchemaDescription();

        return <<<EOT
# Роль и цель
Ты — аналитик структуры экзамена.

**Цель этого запроса:** Сформируй **только скелет экзамена** (структуру без отдельных заданий) строго по схеме `s2_exam_json_archetype_v2.json`.

**Важно:** В этом запросе ты НЕ генерируешь отдельные экзаменационные вопросы (questions). Только структуру секций.

# Входные данные
**Exam Title**: {$examTitle}
**User Input**: {$userInput}
**Exam Description**: {$contextNotes}

{$documentPriorityHint}

# Приоритеты требований (от критичного к желательному)

1. **КРИТИЧНО (MUST)** - нарушение сломает результат:
   - Валидный JSON без синтаксических ошибок
   - Все required поля присутствуют (id, version, meta, pass_policy, sections)
   - pass_policy.mode ОБЯЗАТЕЛЬНО сопровождается соответствующим threshold полем:
     * Если mode = "overall_only" → ОБЯЗАТЕЛЬНО указать overall_threshold_percent (число 0-100)
     * Если mode = "per_section_only" → ОБЯЗАТЕЛЬНО указать per_section_threshold_percent (число 0-100)
     * Если mode = "mixed" → ОБЯЗАТЕЛЬНО указать ОБА поля (overall_threshold_percent И per_section_threshold_percent)
   - Каждая секция содержит required поля: id, skill, duration_min, max_score, min_pass_percent
   - Типы данных соответствуют схеме (string, integer, number, object, array)

2. **ВАЖНО (SHOULD)** - желательно соблюдать:
   - Точные тайминги из официальных источников
   - Корректные названия секций и уровни
   - Realistic score distributions

3. **ЖЕЛАТЕЛЬНО** - дополнительные улучшения:
   - Красивое форматирование
   - Подробные optional поля
   - Дополнительная информация в метаданных

# Что нужно вернуть
Верни **валидный JSON экзамена**, содержащий:
- `id` (string) - уникальный идентификатор экзамена
- `version` (string) - версия схемы, по умолчанию "2.0"
- `meta` (object) - метаданные экзамена:
  - `name` (string) - название экзамена
  - `language` (string) - ISO 639-1 код целевого языка
  - `level` (string) - уровень: A1|A2|B1|B2|C1|C2
  - `provider` (string, optional) - провайдер экзамена
  - `session` (string, optional) - сессия/дата
  - `tags` (array, optional) - теги
- `pass_policy` (object) - правила прохождения:
  - `mode` (string) - режим: overall_only|per_section_only|mixed
  - `overall_threshold_percent` (number, optional) - общий порог прохождения
  - `per_section_threshold_percent` (number, optional) - порог по секциям
- `policies` (object) - глобальные правила проведения:
  - `navigation` (object, optional) - правила навигации
  - `answer_transfer_window_sec` (integer, optional) - время для переноса ответов
  - `proctoring` (object, optional) - требования прокторинга
- `resources` (object, optional) - глобальные ресурсы экзамена
- `sections[]` (array) - секции экзамена

## Требования к каждой секции (`sections[]`):
Для КАЖДОЙ секции заполни **required** поля:
- `id` (string) - уникальный ID секции
- `skill` (string) - навык: listening|reading|writing|speaking|use_of_english|grammar_lexis
- `duration_min` (integer) - длительность секции в минутах
- `max_score` (number) - максимальный балл
- `min_pass_percent` (number) - минимальный процент для прохождения

Опциональные поля секции:
- `title` (string) - название секции
- `time_policy` (string) - per_section|per_question|hybrid
- `weight` (number) - вес секции 0-1
- `pair_mode` (string) - solo|paired (для speaking)
- `playbacks` (integer) - количество воспроизведений (для listening): 1 или 2
- `phases` (array) - фазы выполнения
- `navigation` (object) - правила навигации для секции
- `expected_question_count` (object) - ожидаемое количество экзаменационных вопросов:
  - `target` (integer) - целевое количество
  - `min` (integer) - минимум
  - `max` (integer) - максимум

## Ограничения:
- **Никаких** `questions[]` - экзаменационные вопросы генерируются в Phase B
- **Никаких** `assembly` - это для Phase B
- Строго соответствуй ключам и типам из `s2_exam_json_archetype_v2.json`
- Не используй `pattern`, `type_specific` и другие нестандартные поля

# Timing Requirements (CRITICAL):
1. **Total Exam Duration**: MUST extract from official sources
   - Look for explicit statements like "Total time: 2 hours 45 minutes"
   - If only section durations available, sum them to get total
   - Store in exam metadata or use for validation
2. **Section Duration**: IMPORTANT for each section
   - Listening: X minutes, Reading: Y minutes, etc.
   - Use `duration_min` field in each section
3. **Expected Question Count**: Use `expected_question_count` if question counts are known
   - Helps Phase B understand how many exam questions to generate

# Source Quality & Research:
{$localizationHint}

**MINIMUM SOURCE REQUIREMENTS:**
- At least 3-4 high-quality sources (web + documents)
- Prefer official exam provider sites (.gov, .edu, certification bodies)
- Include at least 1 primary source with direct evidence (official PDFs, exam specifications)
- Ensure diversity: max 2 from the same publisher/domain

**PRIORITY ORDER:**
1. **Official exam specifications** - highest authority
2. **Government/certification body sites** - trusted sources
3. **Educational institutions** - supplementary information

**URL VALIDATION (CRITICAL):**
Before including any source, verify that the URL:
1. Does NOT contain error indicators: "404", "not found", "page doesn't exist"
2. Is accessible and contains actual exam information
3. Is not a generic landing page - must be specific about exam structure

**EXAMPLES OF GOOD SOURCES:**
✓ Official provider domains (e.g., ielts.org, ets.org, goethe.de)
✓ Specific pages about exam structure/format/content
✓ Direct information about sections, timing, and pass requirements

**DO NOT USE:**
✗ Commercial prep sites without official credentials
✗ User-generated content (forums, Reddit, Quora)
✗ Broken/error pages
✗ Generic landing pages without specific exam information

{$retryHint}

# Формат ответа
Только JSON (без комментариев и без markdown оформления).

{$schemaDescription}

{$documentsHint}

# Финальная проверка перед ответом

**Перед тем как вернуть JSON, обязательно проверь:**

1. ✅ JSON синтаксически корректен (все кавычки, запятые, скобки на месте)
2. ✅ Все required поля присутствуют:
   - exam level: id, version, meta, pass_policy, sections
   - meta level: name, language, level
   - pass_policy level: mode + соответствующие threshold поля (см. правило выше!)
   - section level (для КАЖДОЙ секции): id, skill, duration_min, max_score, min_pass_percent
3. ✅ pass_policy.mode соответствует требованиям:
   - overall_only → overall_threshold_percent есть
   - per_section_only → per_section_threshold_percent есть
   - mixed → ОБА threshold поля есть
4. ✅ Типы данных правильные:
   - id, skill, level: string
   - duration_min: integer (целое число)
   - max_score, min_pass_percent, threshold_percent: number (может быть дробным)
5. ✅ НЕТ полей questions[] или assembly{} в секциях (это Phase B, не Phase A!)
6. ✅ Все skill значения из списка: listening|reading|writing|speaking|use_of_english|grammar_lexis

Если хотя бы одна проверка не прошла — исправь JSON и проверь снова.

# Timeframe
Complete this analysis in ~1.5-2 minutes. Focus on structure, not individual exam questions.
EOT;
    }

    /**
     * Get document priority hint
     */
    private static function getDocumentPriorityHint(string $documentsHint): string
    {
        if (empty($documentsHint)) {
            return '';
        }

        return <<<'HINT'
**CRITICAL - Document Priority:**
The user has provided exam documents (past papers, official guidelines, sample questions, etc.).
These documents are PRIMARY SOURCES and must take priority over generic web information.

REQUIREMENTS:
1. Read and analyze ALL provided documents carefully
2. Extract timing information with STRICT PRIORITY:
   - PRIORITY 1 (CRITICAL): Total exam duration (e.g., "Total time: 2 hours 45 minutes")
   - PRIORITY 2 (IMPORTANT): Section durations (e.g., "Listening: 30 min", "Reading: 60 min")
3. Use EXACT timing values from documents (not estimates)
4. Extract section structure, pass requirements, and policies from documents
5. List documents in metadata with provenance tracking
6. Only use web sources to supplement missing information, not to contradict documents

The documents contain REAL exam materials - treat them as ground truth.
HINT;
    }

    /**
     * Get localization hint for local sources
     */
    private static function getLocalizationHint(?array $userInput): string
    {
        if (!$userInput) {
            return '';
        }

        $country = $userInput['where']['country'] ?? null;
        $language = $userInput['language'] ?? null;

        if ($country) {
            return "\n**Local Sources Requirement:**\n" .
                   "Include at least 1-2 sources from {$country} in local language if available.\n" .
                   "Prioritize official government sites and certification bodies from {$country}.";
        }

        if ($language) {
            return "\n**Local Sources Requirement:**\n" .
                   "Include at least 1-2 sources in {$language} language.\n" .
                   "Prioritize official certification bodies and exam providers in {$language}.";
        }

        return '';
    }

    /**
     * Get the JSON schema description for Phase A response
     */
    private static function getSchemaDescription(): string
    {
        $schema = self::getSchema();

        return <<<SCHEMA
# Response Schema (STRICT):
{$schema}

This schema defines the expected structure for Phase A output.
Follow it strictly. Any deviation will cause validation errors.
SCHEMA;
    }

    /**
     * Get the JSON schema for Phase A response
     */
    public static function getSchema(): string
    {
        $schemaArray = [
            'id' => 'string (unique exam identifier)',
            'version' => 'string (schema version, default: "2.0")',
            'meta' => [
                'name' => 'string (exam name)',
                'language' => 'string (ISO 639-1 target language code)',
                'level' => 'string (A1|A2|B1|B2|C1|C2)',
                'provider' => 'string (optional - exam provider)',
                'session' => 'string (optional - exam session/date)',
                'tags' => ['string (optional - exam tags)'],
            ],
            'pass_policy' => [
                'mode' => 'string (overall_only|per_section_only|mixed)',
                'overall_threshold_percent' => 'number (optional - 0-100)',
                'per_section_threshold_percent' => 'number (optional - 0-100)',
            ],
            'policies' => [
                'navigation' => [
                    'backtracking' => 'boolean (optional - default true)',
                ],
                'answer_transfer_window_sec' => 'integer (optional)',
                'proctoring' => [
                    'camera' => 'boolean (optional)',
                    'mic' => 'boolean (optional)',
                    'screen' => 'boolean (optional)',
                ],
                'policy_precedence' => 'string (optional - default: "question>section>exam")',
            ],
            'resources' => 'object (optional - global exam resources)',
            'sections' => [
                [
                    'id' => 'string (unique section ID)',
                    'title' => 'string (optional - section title)',
                    'skill' => 'string (listening|reading|writing|speaking|use_of_english|grammar_lexis)',
                    'duration_min' => 'integer (section duration in minutes)',
                    'time_policy' => 'string (optional - per_section|per_question|hybrid)',
                    'max_score' => 'number (maximum score for section)',
                    'weight' => 'number (optional - section weight 0-1)',
                    'min_pass_percent' => 'number (minimum pass percentage 0-100)',
                    'pair_mode' => 'string (optional - solo|paired for speaking)',
                    'playbacks' => 'integer (optional - 1 or 2 for listening)',
                    'phases' => ['string (optional - execution phases)'],
                    'navigation' => [
                        'backtracking' => 'boolean (optional)',
                    ],
                    'expected_question_count' => [
                        'target' => 'integer (optional - target exam question count)',
                        'min' => 'integer (optional - minimum exam questions)',
                        'max' => 'integer (optional - maximum exam questions)',
                    ],
                ],
            ],
        ];

        return json_encode($schemaArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the raw schema array (for programmatic use)
     */
    public static function getSchemaArray(): array
    {
        return [
            'id' => 'string',
            'version' => 'string',
            'meta' => [
                'name' => 'string',
                'language' => 'string',
                'level' => 'string',
                'provider' => 'string',
                'session' => 'string',
                'tags' => ['string'],
            ],
            'pass_policy' => [
                'mode' => 'string',
                'overall_threshold_percent' => 'number',
                'per_section_threshold_percent' => 'number',
            ],
            'policies' => [
                'navigation' => ['backtracking' => 'boolean'],
                'answer_transfer_window_sec' => 'integer',
                'proctoring' => [
                    'camera' => 'boolean',
                    'mic' => 'boolean',
                    'screen' => 'boolean',
                ],
            ],
            'resources' => 'object',
            'sections' => [
                [
                    'id' => 'string',
                    'title' => 'string',
                    'skill' => 'string',
                    'duration_min' => 'integer',
                    'time_policy' => 'string',
                    'max_score' => 'number',
                    'weight' => 'number',
                    'min_pass_percent' => 'number',
                    'pair_mode' => 'string',
                    'playbacks' => 'integer',
                    'phases' => ['string'],
                    'navigation' => ['backtracking' => 'boolean'],
                    'expected_question_count' => [
                        'target' => 'integer',
                        'min' => 'integer',
                        'max' => 'integer',
                    ],
                ],
            ],
        ];
    }
}
