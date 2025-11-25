<?php

namespace App\Services\LanguageApp\Prompts;

/**
 * Section Assembly Plan Generation (Parallel Phase B)
 *
 * Generates assembly configuration for a SINGLE exam section.
 * Used for parallel section generation to speed up structure building.
 */
class PromptSectionAssembly
{
    /**
     * Build the prompt for single section assembly plan generation
     */
    public static function build(
        string $examTitle,
        string $userInput,
        string $contextNotes,
        array $sectionSkeleton,
        array $fullSkeleton,
        ?string $retryHint = null
    ): string {
        $sectionJson = json_encode($sectionSkeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $fullSkeletonJson = json_encode($fullSkeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $sectionId = $sectionSkeleton['id'] ?? 'unknown';
        $questionTypesEnum = self::getQuestionTypesEnum();
        $assemblyModesDescription = self::getAssemblyModesDescription();

        return <<<EOT
# Роль и цель
Ты — планировщик сборки экзаменационных вопросов.

**Цель этого запроса:** Подготовь **Assembly Plan** (план сборки) И **Question Archetypes** (шаблоны вопросов) для ОДНОЙ секции экзамена: **{$sectionId}**.

**Важно:** В этом запросе ты создаёшь:
1. **question_archetypes** - шаблоны вопросов (типы, сложность, базовая конфигурация)
2. **assembly** - конфигурацию сборки (pool/blueprint/inline)

Ты НЕ создаёшь тексты вопросов, stimulus, конкретные options. Только архетипы и стратегию сборки.

⚠️ **CRITICAL VALIDATION RULE for question_groups:**
When using `inline` mode with `question_groups`, EVERY group MUST have a non-empty `stimulus` field:
- **Listening sections:** `"stimulus": {"audio": ["placeholder_audio.mp3"]}`
- **Reading sections:** `"stimulus": {"text_html": "<article><h2>Title</h2><p>Placeholder text...</p></article>"}`
- **NEVER:** `"stimulus": {}` or `"stimulus": null` → ❌ VALIDATION WILL FAIL!

# Входные данные

**Exam Title**: {$examTitle}
**User Input**: {$userInput}
**Exam Description**: {$contextNotes}

**Section Skeleton** (структура ЭТОЙ секции из Phase A):
```json
{$sectionJson}
```

**Full Exam Skeleton** (для контекста - как эта секция вписывается в экзамен):
```json
{$fullSkeletonJson}
```

# Приоритеты требований

1. **КРИТИЧНО (MUST)**:
   - Валидный JSON без синтаксических ошибок
   - РОВНО ОДИН assembly mode (pool|blueprint|inline) для секции
   - В filters.type использовать ТОЛЬКО типы из Question Types enum
   - Для blueprint: сумма `pick` = `assertions.total_questions_equals`
   - Не создавать текст вопросов (instructions, stimulus, options, answer_key)

2. **ВАЖНО (SHOULD)**:
   - Правильный выбор assembly mode для типа секции
   - Realistic filters (difficulty, cefr levels, skills_measured)
   - Корректные assertions для валидации плана

# Что нужно сделать

## Часть 1: Создать Question Archetypes для секции

Создай массив `question_archetypes` — шаблоны вопросов для этой секции.

**Структура Question Archetype:**
```json
{
  "id": "listen_mcq_01",
  "type": "listen_mcq",
  "name": "Listening MCQ - Main Idea",
  "difficulty": "medium",
  "config": {
    "options_count": 4,
    "duration_sec": 120,
    "scoring": {
      "max_points": 1,
      "partial_credit": false
    }
  }
}
```

**Правила:**
- Создай 2-5 archetypes для секции
- Разнообразие: разные типы вопросов, разная сложность
- ОБЯЗАТЕЛЬНО укажи `config.scoring.max_points` для каждого archetype
- Не дублируй IDs

## Часть 2: Выбрать Assembly Mode

**Доступные режимы:**
{$assemblyModesDescription}

### 🚨 CRITICAL REQUIREMENT: Listening секции MUST use inline + question_groups

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

### ✅ CORRECT Example: Listening with question_groups
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

### ❌ INCORRECT Examples (DO NOT DO THIS!)

**❌ Listening with pool mode (WRONG!):**
```json
{
  "assembly": {
    "mode": "pool",  // ← WRONG! Never use pool for listening!
    "filters": [{"type": "listen_mcq", "pick": 10}]
  }
}
```
**Why wrong:** Pool cannot handle shared audio stimulus. Each question would need its own audio.

**❌ Listening with blueprint mode (WRONG!):**
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

### ✅ CORRECT Example: Reading Section with question_groups

**Reading sections with shared text passage MUST also use inline + question_groups:**

```json
{
  "assembly": {
    "mode": "inline",
    "question_groups": [
      {
        "id": "reading-task-1",
        "title": "Text 1 - Article about Technology",
        "stimulus": {
          "text_html": "<article><h2>The Future of AI</h2><p>Artificial intelligence is transforming...</p></article>"
        },
        "playback_settings": null,
        "questions": [
          {"id": "q1", "type": "read_mcq"},
          {"id": "q2", "type": "read_mcq"},
          {"id": "q3", "type": "read_true_false"},
          {"id": "q4", "type": "read_true_false"},
          {"id": "q5", "type": "read_yes_no_ng"}
        ]
      },
      {
        "id": "reading-task-2",
        "title": "Text 2 - Letter to the Editor",
        "stimulus": {
          "text_html": "<article><h2>Dear Editor,</h2><p>I am writing to express my concern...</p></article>"
        },
        "playback_settings": null,
        "questions": [
          {"id": "q6", "type": "read_mcq"},
          {"id": "q7", "type": "read_mcq"},
          {"id": "q8", "type": "matching"},
          {"id": "q9", "type": "fill_in_blank"}
        ]
      }
    ]
  }
}
```

**⚠️ IMPORTANT for Reading stimulus:**
- Use `"stimulus": {"text_html": "<article>...</article>"}` (NOT empty!)
- The `text_html` field MUST contain placeholder text (AI will not generate full text)
- Use realistic placeholder like `"<article><h2>Title</h2><p>Text content...</p></article>"`
- NEVER leave stimulus empty: `"stimulus": {}` → ❌ VALIDATION WILL FAIL!

### Рекомендации по выбору для других секций:
- **Pool** → для Grammar/Use of English секций где вопросы независимы
- **Blueprint** → для Writing/Speaking секций с разными уровнями сложности
- **Inline** → для Reading/Listening секций с question groups (shared stimulus + несколько вопросов)

## Часть 3: Создать Assembly Configuration

### Если выбран Pool:
```json
{
  "mode": "pool",
  "filters": [
    {
      "type": "listen_mcq",
      "difficulty": ["easy", "medium"],
      "pick": 5,
      "tags": ["main_idea"]
    }
  ],
  "assertions": {
    "total_questions_equals": 10,
    "max_points_sum_equals": 10
  }
}
```

### Если выбран Blueprint:
```json
{
  "mode": "blueprint",
  "slots": [
    {
      "slot_id": "easy_task",
      "type": "writing_prompt",
      "difficulty": "easy",
      "pick": 1,
      "tags": ["email"]
    },
    {
      "slot_id": "hard_task",
      "type": "writing_prompt",
      "difficulty": "hard",
      "pick": 1,
      "tags": ["essay"]
    }
  ],
  "assertions": {
    "total_questions_equals": 2
  }
}
```

### Если выбран Inline:
```json
{
  "mode": "inline",
  "question_groups": [
    {
      "id": "listening-question-1",
      "title": "Task I",
      "stimulus": {
        "audio": ["placeholder_url"]
      },
      "questions": [
        {
          "id": "q1",
          "type": "single_select"
        }
      ]
    }
  ]
}
```

# Output Format

Верни ТОЛЬКО JSON с секцией в формате:
```json
{
  "id": "{$sectionId}",
  "title": "название секции",
  "skill": "listening|reading|writing|speaking",
  "duration_min": 30,
  "max_score": 30,
  "question_archetypes": [
    // массив archetypes
  ],
  "assembly": {
    "mode": "pool|blueprint|inline",
    // конфигурация mode
  },
  "questions": [
    // копируй из section skeleton если есть
  ]
}
```

# Question Types Enum
{$questionTypesEnum}

{$retryHint}

EOT;
    }

    private static function getQuestionTypesEnum(): string
    {
        return <<<EOT
**Допустимые типы вопросов** (используй ТОЛЬКО эти):

**Listening:**
- `listen_mcq` - Multiple Choice (audio stimulus)
- `dictation` - Spelling/dictation (audio → text input)
- `listen_true_false` - True/False statements
- `listen_yes_no_ng` - Yes/No/Not Given

**Reading:**
- `read_mcq` - Multiple Choice (text stimulus)
- `read_true_false` - True/False statements
- `read_yes_no_ng` - Yes/No/Not Given
- `matching` - Matching headings/features
- `sentence_completion` - Complete sentences from passage
- `short_answer` - Short answer questions

**Writing:**
- `writing_prompt` - Essay/letter writing
- `translation` - Translate text

**Speaking:**
- `speaking_prompt` - Speaking with prompt
- `roleplay` - Role-play dialogue

**Universal:**
- `single_select` - Single choice (generic MCQ)
- `multi_select` - Multiple choice (select all that apply)
- `text_input` - Free text input
- `rating_scale` - Rating scale (1-5, Likert)
EOT;
    }

    private static function getAssemblyModesDescription(): string
    {
        return <<<EOT
1. **pool** — Pull questions from a database pool by filters (type, difficulty, tags)
   - Use case: MCQ sections with many similar questions
   - Example: "Pick 10 listening MCQs, difficulty = easy/medium"

2. **blueprint** — Define slots with specific requirements, pull from pool
   - Use case: Structured sections with fixed composition
   - Example: "Slot 1: 1 easy writing question, Slot 2: 1 hard writing question"

3. **inline** — Manually define questions or question groups in assembly config
   - Use case: Unique questions that don't fit pool (e.g. specific listening passages)
   - Example: Question groups with shared stimulus
EOT;
    }
}
