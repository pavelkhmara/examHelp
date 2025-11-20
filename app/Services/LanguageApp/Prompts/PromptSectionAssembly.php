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
   - Для blueprint: сумма `pick` = `assertions.total_tasks_equals`
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

**Рекомендации по выбору:**
- **Pool** → для секций с фиксированными типами заданий (listening, reading с MCQ)
- **Blueprint** → для секций с известным составом, но разными уровнями (writing, speaking с промптами)
- **Inline** → для секций с уникальными заданиями (question groups, integrated tasks)

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
    "total_tasks_equals": 10,
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
    "total_tasks_equals": 2
  }
}
```

### Если выбран Inline:
```json
{
  "mode": "inline",
  "question_groups": [
    {
      "id": "listening-task-1",
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
  "tasks": [
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
- `writing_prompt` - Essay/letter writing task
- `translation` - Translate text

**Speaking:**
- `speaking_prompt` - Speaking task with prompt
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
   - Example: "Slot 1: 1 easy writing task, Slot 2: 1 hard writing task"

3. **inline** — Manually define questions or question groups in assembly config
   - Use case: Unique questions that don't fit pool (e.g. specific listening passages)
   - Example: Question groups with shared stimulus
EOT;
    }
}
