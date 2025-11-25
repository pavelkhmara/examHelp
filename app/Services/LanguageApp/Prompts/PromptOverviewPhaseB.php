<?php

namespace App\Services\LanguageApp\Prompts;

/**
 * Phase B: Assembly Plan Generation
 *
 * Generates assembly configuration for exam sections without creating actual question content.
 * Output conforms to s2_exam_json_archetype_v2.json schema with assembly modes configured.
 */
class PromptOverviewPhaseB
{
    /**
     * Build the Phase B prompt for assembly plan generation
     */
    public static function build(
        string $examTitle,
        string $userInput,
        string $contextNotes,
        array $phaseASkeleton,
        ?string $retryHint = null,
        ?array $userInputParsed = null,
        string $documentsHint = ''
    ): string {
        $skeletonJson = json_encode($phaseASkeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $questionTypesEnum = self::getQuestionTypesEnum();
        $assemblyModesDescription = self::getAssemblyModesDescription();
        $schemaDescription = self::getSchemaDescription();

        return <<<EOT
# Роль и цель
Ты — планировщик сборки экзаменационных вопросов.

**Цель этого запроса:** Подготовь **Assembly Plan** (план сборки) И **Question Archetypes** (шаблоны вопросов) для ВСЕХ секций экзамена — строго по `s2_exam_json_archetype_v2.json`.

**Важно:** В этом запросе ты создаёшь:
1. **question_archetypes** - шаблоны вопросов (типы, сложность, базовая конфигурация)
2. **assembly** - конфигурацию сборки (pool/blueprint/inline)

Ты НЕ создаёшь тексты вопросов, stimulus, конкретные options. Только архетипы и стратегию сборки.

# Входные данные
**Exam Title**: {$examTitle}
**User Input**: {$userInput}
**Exam Description**: {$contextNotes}

**Phase A Skeleton** (exam structure created in Phase A):
```json
{$skeletonJson}
```

# Приоритеты требований (от критичного к желательному)

1. **КРИТИЧНО (MUST)** - нарушение сломает результат:
   - Валидный JSON без синтаксических ошибок
   - КАЖДАЯ секция из Phase A skeleton имеет ровно ОДИН assembly mode (pool|blueprint|inline)
   - В filters.type использовать ТОЛЬКО типы из Question Types enum (см. ниже)
   - Для blueprint: сумма всех `pick` в слотах = `assertions.total_questions_equals`
   - Не создавать текст вопросов (instructions, stimulus, options, answer_key и т.д.)
   - Не использовать удалённые поля (`pattern`, `type_specific`)

2. **ВАЖНО (SHOULD)** - желательно соблюдать:
   - Правильный выбор assembly mode для типа секции
   - Realistic filters (difficulty, cefr levels, skills_measured)
   - Корректные assertions для валидации плана

3. **ЖЕЛАТЕЛЬНО** - дополнительные улучшения:
   - Подробные io_signature для каждого filter
   - Дополнительные теги и метаданные

# Что нужно сделать

## Часть 1: Создать Question Archetypes для каждой секции

Для КАЖДОЙ секции создай массив `question_archetypes` — это шаблоны вопросов, которые будут использоваться при генерации.

**Структура Question Archetype:**
```json
{
  "id": "listen_mcq_01",                    // уникальный ID шаблона
  "type": "listen_mcq",                     // тип вопроса из Question Types enum
  "name": "Listening MCQ - Main Idea",      // название шаблона
  "difficulty": "medium",                    // easy | medium | hard
  "config": {                                // базовая конфигурация
    "options_count": 4,                      // количество вариантов ответа (для MCQ)
    "min_word_count": 150,                   // минимум слов (для writing)
    "max_word_count": 200,                   // максимум слов (для writing)
    "duration_sec": 120,                     // время на вопрос в секундах
    "scoring": {
      "max_points": 1,                       // максимум баллов
      "partial_credit": false                // частичные баллы
    }
  }
}
```

**Правила создания archetypes:**

1. **Количество**: создай 2-5 archetypes для каждой секции (в зависимости от разнообразия типов вопросов)
2. **Разнообразие**: если секция использует разные типы вопросов → создай по archetype для каждого типа
3. **Сложность**: для экзаменов с градацией сложности создай archetypes разной difficulty
4. **Config**: заполни только базовые параметры (options_count, word_count, duration, scoring.max_points)

**Примеры по секциям:**

**Listening Section** → archetypes примеры:
```json
"question_archetypes": [
  {
    "id": "listen_mcq_detail",
    "type": "listen_mcq",
    "name": "Detail Extraction MCQ",
    "difficulty": "medium",
    "config": {"options_count": 4, "scoring": {"max_points": 1}}
  },
  {
    "id": "listen_completion",
    "type": "note_completion",
    "name": "Note Completion",
    "difficulty": "hard",
    "config": {"scoring": {"max_points": 1}}
  }
]
```

**Reading Section** → archetypes примеры:
```json
"question_archetypes": [
  {
    "id": "read_mcq_inference",
    "type": "single_select",
    "name": "Inference MCQ",
    "difficulty": "medium",
    "config": {"options_count": 4, "scoring": {"max_points": 1}}
  },
  {
    "id": "read_tfng",
    "type": "true_false",
    "name": "True/False/Not Given",
    "difficulty": "hard",
    "config": {"scoring": {"max_points": 1}}
  },
  {
    "id": "read_matching",
    "type": "matching",
    "name": "Heading Matching",
    "difficulty": "medium",
    "config": {"scoring": {"max_points": 1}}
  }
]
```

**Writing Section** → archetypes примеры:
```json
"question_archetypes": [
  {
    "id": "writing_question_1",
    "type": "writing_prompt",
    "name": "Short Writing Task (150 words)",
    "difficulty": "medium",
    "config": {
      "min_word_count": 150,
      "duration_sec": 1200,
      "scoring": {"max_points": 33}
    }
  },
  {
    "id": "writing_question_2",
    "type": "writing_prompt",
    "name": "Essay Task (250 words)",
    "difficulty": "hard",
    "config": {
      "min_word_count": 250,
      "duration_sec": 2400,
      "scoring": {"max_points": 67}
    }
  }
]
```

**Speaking Section** → archetypes примеры:
```json
"question_archetypes": [
  {
    "id": "speaking_part_1",
    "type": "speaking_prompt",
    "name": "Introduction & Interview",
    "difficulty": "easy",
    "config": {"duration_sec": 300, "scoring": {"max_points": 25}}
  },
  {
    "id": "speaking_part_2",
    "type": "speaking_prompt",
    "name": "Individual Long Turn",
    "difficulty": "medium",
    "config": {"duration_sec": 240, "scoring": {"max_points": 25}}
  },
  {
    "id": "speaking_part_3",
    "type": "speaking_prompt",
    "name": "Two-way Discussion",
    "difficulty": "hard",
    "config": {"duration_sec": 300, "scoring": {"max_points": 50}}
  }
]
```

## Часть 2: Определить Assembly Mode для каждой секции

Для КАЖДОЙ секции из Phase A skeleton определи ОДИН из трёх режимов сборки:

{$assemblyModesDescription}

# Важно: Filters и Question Types

## Available Question Types (STRICT ENUM)
Используй ТОЛЬКО эти типы в filters:
{$questionTypesEnum}

## Filters Structure
`filters` — это свободный объект для критериев отбора вопросов:

**Обязательные поля в filters:**
- `type` (array) - массив допустимых типов вопросов из Question Types enum выше

**Опциональные поля в filters:**
- `skills_measured` (array) - навыки, которые проверяет вопрос (e.g., ["reading comprehension", "vocabulary"])
- `io_signature` (object) - рекомендуемая матрица вход/выход:
  - `stimulus` (array) - типы стимулов: ["text", "image", "audio", "video"]
  - `response` (array) - типы ответов: ["selection", "text", "audio", "numeric", "spans", "ordering", "matching"]
- `difficulty` (array) - уровни сложности: ["easy", "medium", "hard"]
- `cefr` (array) - CEFR уровни: ["A1", "A2", "B1", "B2", "C1", "C2"]
- `topic` (array) - темы вопросов (free-form strings)
- `tags` (array) - дополнительные теги

**Примеры filters:**

```json
// Listening section filter
{
  "type": ["single_select", "multi_select", "listen_mcq"],
  "skills_measured": ["listening comprehension", "detail extraction"],
  "io_signature": {
    "stimulus": ["audio"],
    "response": ["selection"]
  },
  "difficulty": ["medium", "hard"]
}

// Reading section filter
{
  "type": ["single_select", "true_false", "gap_cloze", "matching"],
  "skills_measured": ["reading comprehension", "inference"],
  "io_signature": {
    "stimulus": ["text"],
    "response": ["selection", "text"]
  }
}

// Writing section filter
{
  "type": ["writing_prompt"],
  "skills_measured": ["written production", "coherence", "task achievement"],
  "io_signature": {
    "stimulus": ["text"],
    "response": ["text"]
  },
  "cefr": ["B2", "C1"]
}
```

## Assertions
Используй `assertions` для валидации плана:

```json
{
  "total_questions_equals": 40,  // ожидаемое ТОЧНОЕ количество вопросов
  "unique_by": ["id"]        // поля для проверки уникальности (обычно ["id"])
}
```

- `total_questions_equals` (integer) - ТОЧНОЕ количество вопросов для секции (используй `expected_question_count.target` из skeleton)
- `unique_by` (array) - поля для проверки уникальности (обычно `["id"]`)

# Выбор режима assembly для секции

## 🚨 CRITICAL REQUIREMENT: Listening секции MUST use inline + question_groups

**Listening секции ОБЯЗАНЫ использовать `inline` mode с `question_groups`:**

⚠️ **VALIDATION WILL FAIL if:**
- Listening section uses `pool` mode → ❌ INCORRECT (validation error)
- Listening section uses `blueprint` mode → ❌ INCORRECT (validation error)
- No `question_groups` in listening section → ❌ INCORRECT (validation error)

✅ **CORRECT structure for listening:**
```json
{
  "assembly": {
    "mode": "inline",  // ← REQUIRED for listening!
    "question_groups": [  // ← REQUIRED! Not optional!
      {
        "id": "listening-task-1",
        "title": "Task I",
        "stimulus": {"audio": ["placeholder_url"]},
        "playback_settings": {"max_plays": 2, "enforcement": "strict"},
        "questions": [
          {"id": "q1", "type": "listen_mcq"},
          {"id": "q2", "type": "listen_mcq"}
        ]
      }
    ]
  }
}
```

**Why question_groups required for listening:**
1. Listening = Task I (audio 1 → Q1-Q6), Task II (audio 2 → Q7-Q12)
2. Each task has ONE shared audio + MULTIPLE questions
3. This structure REQUIRES `question_groups` with shared stimulus
4. Using pool/blueprint for listening is WRONG and will be rejected

**Do NOT use pool or blueprint for listening sections!**

## ✅ CORRECT Examples: Listening with question_groups

### Example 1: Polish B1 Listening
```json
{
  "assembly": {
    "mode": "inline",
    "question_groups": [
      {
        "id": "listening-task-1",
        "title": "Zadanie I",
        "stimulus": {"audio": ["listening_task1.mp3"]},
        "playback_settings": {"max_plays": 1, "enforcement": "strict"},
        "questions": [
          {"id": "q1", "type": "listen_mcq"},
          {"id": "q2", "type": "listen_mcq"},
          {"id": "q3", "type": "listen_mcq"},
          {"id": "q4", "type": "listen_mcq"},
          {"id": "q5", "type": "listen_mcq"},
          {"id": "q6", "type": "listen_mcq"}
        ]
      },
      {
        "id": "listening-task-2",
        "title": "Zadanie II",
        "stimulus": {"audio": ["listening_task2.mp3"]},
        "playback_settings": {"max_plays": 2, "enforcement": "strict"},
        "questions": [
          {"id": "q7", "type": "listen_true_false"},
          {"id": "q8", "type": "listen_true_false"},
          {"id": "q9", "type": "listen_true_false"},
          {"id": "q10", "type": "listen_true_false"}
        ]
      }
    ]
  }
}
```

## ❌ INCORRECT Examples (DO NOT DO THIS!)

### ❌ Listening with pool mode (WRONG!)
```json
{
  "assembly": {
    "mode": "pool",  // ← WRONG! Never use pool for listening!
    "filters": [{"type": "listen_mcq", "pick": 10}]
  }
}
```
**Why wrong:** Pool cannot handle shared audio stimulus. Each question would need its own audio.

### ❌ Listening with blueprint mode (WRONG!)
```json
{
  "assembly": {
    "mode": "blueprint",  // ← WRONG! Never use blueprint for listening!
    "slots": [{"slot_id": "part1", "type": "listen_mcq", "pick": 6}]
  }
}
```
**Why wrong:** Blueprint pulls independent questions from pool. Listening needs grouped questions with shared audio.

**Remember:** Listening ALWAYS needs `inline` + `question_groups`!

## Reading секции

**Для Reading секций:**
→ Используй `inline` с `question_groups` если текст(ы) с несколькими вопросами на каждый
→ Или используй `blueprint` если нужна выборка из пула

## Когда использовать `pool`:
- Grammar/Use of English секции где вопросы независимы друг от друга
- Когда нет shared stimulus между вопросами
- **НИКОГДА для listening с общим аудио!**

## Когда использовать `blueprint`:
- Секция состоит из РАЗНЫХ частей с разными критериями
- Пример: Writing section = Part 1 (email, 150 words) + Part 2 (essay, 250 words)
- С `shared_stimulus: true` в slot → создаст QuestionGroup
- **НИКОГДА для listening секций!**

## Когда использовать `inline` с `question_groups`:
- **ОБЯЗАТЕЛЬНО для Listening секций** с заданиями где 2+ вопроса на одно аудио
- Reading секции где группа вопросов относится к одному тексту
- Пример: Listening (Part I) = 1 аудио → 6 вопросов = 1 question_group

## Когда использовать `inline` с `questions[]`:
- Speaking, Writing где каждый вопрос имеет свой stimulus
- **НЕ заполняй контент вопросов** - только ID placeholders

# Рекомендации по типам вопросов для секций

## Listening:
- `listen_mcq` - listening multiple choice (основной тип для аудирования)
- `single_select`, `multi_select` - выбор ответов после прослушивания
- `dictation` - записать услышанное
- `note_completion` - заполнить заметки

## Reading:
- `single_select`, `multi_select` - множественный выбор по тексту
- `true_false`, `yes_no_ng` - true/false/not given вопросы
- `gap_cloze`, `banked_cloze` - заполнение пропусков
- `matching` - сопоставление заголовков/параграфов
- `short_answer` - краткие ответы на вопросы

## Writing:
- `writing_prompt` - письменное задание с рубрикой оценки

## Speaking:
- `speaking_prompt` - устное задание с рубрикой оценки
- `roleplay` - ролевая игра (для некоторых экзаменов)

## Grammar/Use of English:
- `gap_cloze`, `dropdown_cloze` - грамматические пропуски
- `error_correction` - исправление ошибок
- `transformation` - преобразование предложений

# Ограничения и запреты

**КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:**
- Создавать текст вопросов (instructions, stimulus, text_html)
- Заполнять детали вопросов (interaction.options, response details, scoring.answer_key)
- Использовать `pattern` (это поле удалено)
- Использовать `type_specific` (это поле удалено)

**ОБЯЗАТЕЛЬНО:**
- Для каждой секции задать ровно ОДИН assembly mode
- В filters.type использовать ТОЛЬКО типы из Question Types enum
- Поддерживать `expected_question_count` через `assertions.total_questions_equals`
- Для blueprint: сумма всех `pick` должна равняться `assertions.total_questions_equals`

{$retryHint}

# Формат ответа
Верни полный JSON экзамена **с обновлёнными секциями** (включая assembly configuration).
Не добавляй markdown оформление (```json), только чистый JSON.

{$schemaDescription}

{$documentsHint}

# Финальная проверка перед ответом

**Перед тем как вернуть JSON, обязательно проверь:**

1. ✅ JSON синтаксически корректен (все кавычки, запятые, скобки на месте)
2. ✅ КАЖДАЯ секция из Phase A skeleton присутствует в ответе
3. ✅ КАЖДАЯ секция имеет `question_archetypes` array с 2-5 элементами:
   - Каждый archetype имеет: `id`, `type`, `name`, `difficulty`, `config`
   - Все `type` из Question Types enum
   - `config` содержит базовые параметры (options_count, word_count, duration_sec, scoring)
4. ✅ КАЖДАЯ секция имеет ровно ОДИН assembly mode:
   - `assembly.mode` = "pool" | "blueprint" | "inline"
5. ✅ Для pool mode:
   - Есть `pool_id` (string)
   - Есть `shared_stimulus` (boolean, обычно false)
   - Есть `questions_count` (integer, заменяет старое `pick`)
   - Есть `stimulus_type` (string: audio/text/video/image/none)
   - Есть `filters` с полем `type` (array of question types)
   - Есть `assertions.total_questions_equals` (integer)
6. ✅ Для blueprint mode:
   - Есть `blueprint` (array of slots)
   - Каждый slot имеет: `slot`, `from_pool`, `shared_stimulus`, `questions_count`, `stimulus_type`, `filters`
   - Сумма всех `questions_count` = `assertions.total_questions_equals`
   - `shared_stimulus: true` → это QuestionGroup (2+ вопроса с общим stimulus)
   - `shared_stimulus: false` → отдельные Questions
7. ✅ Для inline mode:
   - Есть `assembly.mode = "inline"`
   - Если listening/reading с 2+ вопросами на один stimulus → используй `question_groups[]` (НЕ `questions[]`!)
   - Если writing/speaking (каждый вопрос = отдельный stimulus) → используй `questions[]`
   - В question_groups[]: каждая группа имеет id, title, stimulus, playback_settings, questions[]
   - В questions[]/questions[]: только `id` и `type` (БЕЗ контента!)
8. ✅ Все типы в `filters.type` И в `question_archetypes[].type` из Question Types enum
9. ✅ НЕТ текста вопросов (instructions, stimulus, options, answer_key) - только шаблоны!
10. ✅ НЕТ удалённых полей (`pattern`, `type_specific`)
11. ✅ Возвращаешь ПОЛНЫЙ JSON экзамена (из Phase A + question_archetypes + assembly configs)

Если хотя бы одна проверка не прошла — исправь JSON и проверь снова.

# Timeframe
Complete this in ~1-1.5 minutes. Focus on assembly strategy, not question content.
EOT;
    }

    /**
     * Get question types enum description
     */
    private static function getQuestionTypesEnum(): string
    {
        return <<<'ENUM'
```
single_select      - single choice (radio buttons)
multi_select       - multiple choice (checkboxes)
true_false         - true/false questions
yes_no_ng          - yes/no/not given questions

dropdown_cloze     - fill blanks from dropdown
gap_cloze          - fill blanks with text
banked_cloze       - fill blanks from word bank

matching           - match items (e.g., headings to paragraphs)
order_sentences    - arrange sentences in order
order_words        - arrange words to form sentence
highlight_text     - highlight specific text spans

short_answer       - short text answer
numeric            - numeric answer
dictation          - write what you hear

writing_prompt     - essay/letter/report writing question
speaking_prompt    - speaking question with rubric

listen_mcq         - listening comprehension multiple choice

translation        - translate text/speech
roleplay           - role-playing conversation
note_completion    - complete notes/forms
```
ENUM;
    }

    /**
     * Get assembly modes description
     */
    private static function getAssemblyModesDescription(): string
    {
        return <<<'MODES'
## 1. `assembly_pool` - Single Pool Selection

Выборка N вопросов из одного пула по единым критериям.

**НОВОЕ: Явная спецификация структуры**
Также должен указывать:
- `shared_stimulus`: boolean - обычно `false` для pool mode (каждый вопрос отдельно)
- `questions_count`: integer - количество вопросов
- `stimulus_type`: string - тип stimulus

**Структура:**
```json
{
  "assembly": {
    "mode": "pool",
    "pool_id": "listening_general",
    "shared_stimulus": false,                  // ✅ обычно false для pool
    "questions_count": 30,                      // ✅ количество вопросов
    "stimulus_type": "audio",                   // ✅ тип stimulus
    "filters": {
      "type": ["listen_mcq", "single_select"],
      "skills_measured": ["listening comprehension"],
      "difficulty": ["medium", "hard"]
    },
    "seed": "optional_random_seed",
    "assertions": {
      "total_questions_equals": 30,
      "unique_by": ["id"]
    }
  }
}
```

**Note:** Pool mode обычно используется для отдельных вопросов (`shared_stimulus: false`), но поля добавляются для единообразия.

## 2. `assembly_blueprint` - Multi-Slot Plan

Детальный план с несколькими слотами/частями, каждая со своими критериями.

**НОВОЕ: Явная спецификация структуры задания**
Каждый slot ДОЛЖЕН указывать:
- `shared_stimulus`: boolean - общий stimulus для всех вопросов (`true`) или каждый вопрос имеет свой (`false`)
- `questions_count`: integer - количество вопросов в этом задании/слоте
- `stimulus_type`: string - "audio"|"text"|"video"|"image"|"none" - тип stimulus

**Примеры:**
- ✅ Listening: 6 вопросов на одно аудио → `shared_stimulus: true, questions_count: 6, stimulus_type: "audio"`
- ✅ Reading: 8 вопросов на один текст → `shared_stimulus: true, questions_count: 8, stimulus_type: "text"`
- ✅ Writing: 2 отдельных эссе → `shared_stimulus: false, questions_count: 2, stimulus_type: "none"`
- ✅ Speaking: 3 speaking prompts → `shared_stimulus: false, questions_count: 3, stimulus_type: "none"`

**Структура:**
```json
{
  "assembly": {
    "mode": "blueprint",
    "blueprint": [
      {
        "slot": "listening_question_1",
        "from_pool": "listening_items_bank",
        "shared_stimulus": true,           // ✅ КРИТИЧНО: общий stimulus
        "questions_count": 6,               // ✅ КРИТИЧНО: 6 вопросов в задании
        "stimulus_type": "audio",           // ✅ КРИТИЧНО: тип stimulus
        "filters": {
          "type": ["listen_mcq"],
          "difficulty": ["medium"],
          "cefr": ["B2", "C1"]
        },
        "weight": 0.2
      },
      {
        "slot": "reading_question_1",
        "from_pool": "reading_texts",
        "shared_stimulus": true,            // один текст
        "questions_count": 8,                // 8 вопросов
        "stimulus_type": "text",
        "filters": {
          "type": ["single_select", "true_false"],
          "difficulty": ["medium"],
          "cefr": ["B2"]
        },
        "weight": 0.4
      },
      {
        "slot": "writing_questions",
        "from_pool": "writing_prompts",
        "shared_stimulus": false,           // каждое эссе отдельно
        "questions_count": 2,                // 2 эссе
        "stimulus_type": "none",             // нет stimulus (только prompt)
        "filters": {
          "type": ["writing_prompt"],
          "difficulty": ["hard"],
          "cefr": ["C1"]
        },
        "weight": 0.4
      }
    ],
    "seed": "optional_seed",
    "assertions": {
      "total_questions_equals": 16,              // 6 + 8 + 2 = 16 вопросов
      "unique_by": ["id"]
    }
  }
}
```

**ВАЖНО:**
- `shared_stimulus: true` → генератор создаст **QuestionGroup** с общим stimulus
- `shared_stimulus: false` → генератор создаст **отдельные Questions** (каждый со своим stimulus)
- `pick` (старое поле) теперь ЗАМЕНЕНО на `questions_count` для ясности

## 3. `assembly_inline` - Fixed Structure

Фиксированная структура без выборки из пула (для строго определённых секций).

### 3A. Inline с отдельными вопросами (questions[])

Используй для секций, где каждый вопрос имеет свой stimulus (например, writing, speaking).

**Структура:**
```json
{
  "assembly": {
    "mode": "inline"
  },
  "questions": [
    {
      "id": "writing_question_1",
      "type": "writing_prompt"
      // НЕ ЗАПОЛНЯЙ instructions, stimulus, scoring - это placeholder!
    },
    {
      "id": "writing_question_2",
      "type": "writing_prompt"
      // НЕ ЗАПОЛНЯЙ детали - только ID и type для структуры
    }
  ]
}
```

### 3B. Inline с question_groups (для listening/reading с общим стимулом)

**КРИТИЧНО:** Используй question_groups для секций, где:
- **2-10+ вопросов используют ОДИН ОБЩИЙ stimulus** (аудио/текст)
- Listening: например, одно аудио → 6 вопросов
- Reading: например, один текст → 8 вопросов

**Структура:**
```json
{
  "assembly": {
    "mode": "inline",
    "question_groups": [
      {
        "id": "listening-question-1",
        "title": "Zadanie I",     // локализованное название задания
        "stimulus": {
          "audio": ["https://example.com/audio1.mp3"]  // ОБЩИЙ stimulus для всех вопросов группы
        },
        "playback_settings": {
          "max_plays": 1,          // сколько раз можно прослушать (1, 2, null=unlimited)
          "enforcement": "strict",  // strict|advisory|none
          "show_counter": true,
          "reset_on_navigation": true
        },
        "metadata": {
          "total_points": 6,       // сумма баллов всех вопросов в группе
          "suggested_time_sec": 300
        },
        "questions": [
          {
            "id": "q1",
            "type": "listen_mcq"
            // НЕ ЗАПОЛНЯЙ details - только id, type
          },
          {
            "id": "q2",
            "type": "listen_mcq"
          },
          // ... до 6-10 вопросов
        ]
      },
      {
        "id": "listening-question-2",
        "title": "Zadanie II",
        "stimulus": {
          "audio": ["https://example.com/audio2.mp3"]
        },
        "playback_settings": {
          "max_plays": 2,
          "enforcement": "advisory"
        },
        "questions": [
          {
            "id": "q7",
            "type": "true_false"
          },
          // ... ещё вопросы
        ]
      }
    ]
  }
}
```

**Когда использовать question_groups:**
- ✅ Listening: 6 коротких аудио → 6 отдельных вопросов = 1 question_group
- ✅ Listening: 1 длинное аудио → 8 вопросов = 1 question_group
- ✅ Reading: 1 текст → 10 вопросов = 1 question_group
- ❌ Writing: 2 essay questions = НЕ используй groups (каждый question отдельно)
- ❌ Speaking: 3 speaking prompts = НЕ используй groups

**Note:**
- Для `inline` с questions[] - добавь `questions[]` как placeholders (только `id` и `type`)
- Для `inline` с question_groups - добавь `question_groups[]` с questions[] placeholders
- НЕ заполняй контент (instructions, stimulus text, options) - это сделает генератор позже!
MODES;
    }

    /**
     * Get the JSON schema description for Phase B response
     */
    private static function getSchemaDescription(): string
    {
        return <<<'SCHEMA'
# Response Schema (STRICT):

Return the FULL exam JSON from Phase A with updated sections.
Each section MUST have question_archetypes array AND assembly configuration.

Required structure per section:
```json
{
  "id": "section_id",
  "skill": "listening|reading|writing|speaking|use_of_english|grammar_lexis",
  "duration_min": 30,
  "max_score": 100,
  "min_pass_percent": 60,
  "question_archetypes": [
    {
      "id": "archetype_id",
      "type": "question_type",
      "name": "Archetype Name",
      "difficulty": "easy|medium|hard",
      "config": {
        "options_count": 4,
        "scoring": {"max_points": 1}
      }
    }
  ],
  "assembly": {
    // ONE OF: assembly_pool | assembly_blueprint | assembly_inline
  }
}
```

The response will be validated against s2_exam_json_archetype_v2.json schema.
SCHEMA;
    }

    /**
     * Get the raw schema array (for programmatic use)
     */
    public static function getSchemaArray(): array
    {
        return [
            'full_exam_json' => 'Full exam structure from Phase A with assembly configs added to each section',
            'sections' => [
                'assembly_pool' => [
                    'mode' => 'pool',
                    'pool_id' => 'string',
                    'pick' => 'integer',
                    'filters' => [
                        'type' => ['array of question types'],
                        'skills_measured' => ['optional array'],
                        'io_signature' => ['optional object'],
                        'difficulty' => ['optional array'],
                        'cefr' => ['optional array'],
                    ],
                    'assertions' => [
                        'total_questions_equals' => 'integer',
                        'unique_by' => ['array'],
                    ],
                ],
                'assembly_blueprint' => [
                    'mode' => 'blueprint',
                    'blueprint' => [
                        [
                            'slot' => 'string',
                            'from_pool' => 'string',
                            'pick' => 'integer',
                            'filters' => 'object',
                            'weight' => 'number',
                        ],
                    ],
                    'assertions' => 'object',
                ],
                'assembly_inline' => [
                    'mode' => 'inline',
                ],
            ],
        ];
    }
}
