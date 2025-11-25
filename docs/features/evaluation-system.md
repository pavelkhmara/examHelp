# Система оценки ответов (Evaluation System)

## Текущее состояние

### Компоненты

| Компонент | Путь | Статус |
|-----------|------|--------|
| EvaluationService | `app/Services/LanguageApp/EvaluationService.php` | ✅ Работает |
| EvaluationController | `app/Http/Controllers/Api/EvaluationController.php` | ✅ Работает |
| Evaluation Model | `app/Models/Evaluation.php` | ✅ Работает |
| RunTextEvaluationJob | `app/Jobs/RunTextEvaluationJob.php` | ✅ Async режим |

### Поддерживаемые типы вопросов

| Тип | Поддержка | Метод оценки |
|-----|-----------|--------------|
| writing_prompt | ✅ | Эвристики (similarity) |
| speaking_prompt | ✅ | Эвристики (similarity) |
| single_select | ❌ | Не реализовано |
| multi_select | ❌ | Не реализовано |
| true_false | ❌ | Не реализовано |
| yes_no_ng | ❌ | Не реализовано |
| fill_blank | ❌ | Не реализовано |
| matching | ❌ | Не реализовано |

---

## API Reference

### POST /api/evaluate/text

Оценка текстового ответа пользователя.

**Request:**
```json
{
  "exam_id": "uuid",
  "category_id": 123,        // optional
  "question_id": 456,        // optional (ExamExampleQuestion ID)
  "answer_text": "User's answer text...",
  "async": false             // optional, default: false
}
```

**Response (sync):**
```json
{
  "ok": true,
  "score": 75,
  "feedback": "Decent answer: try to add key points and make the structure clearer.",
  "rubric_breakdown": {
    "content": 60,
    "clarity": 70,
    "language": 80
  },
  "match": "average"
}
```

**Response (async):**
```json
{
  "ok": true,
  "queued": true,
  "task_id": 789
}
```

---

## Архитектура

### Поток данных

```
Mobile App → POST /api/evaluate/text → EvaluationController
                                            │
                              ┌─────────────┴─────────────┐
                              │                           │
                         sync=true                   async=true
                              │                           │
                              ▼                           ▼
                      EvaluationService         RunTextEvaluationJob
                              │                           │
                              ▼                           ▼
                      Heuristic Scoring          Queue Worker
                              │                           │
                              ▼                           ▼
                      GenerationLog             EvaluationService
                      Evaluation                          │
                              │                           ▼
                              ▼                   GenerationTask.result
                         Response
```

### Алгоритмы эвристик

**1. Similarity (0-100)**
- Использует PHP `similar_text()` для сравнения с эталонами
- Сравнивает с good_answer, average_answer, bad_answer
- Финальный score = max взвешенных similarity

**2. Overlap (0-1)**
- Доля общих "значимых" слов с эталоном
- Фильтрует слова короче 3 символов
- Используется для rubric.content

**3. Clarity (0-1)**
- Средняя длина предложений
- Наличие пунктуации (запятые, точки с запятой)
- Используется для rubric.clarity

**4. Language (0-1)**
- Лексическое разнообразие (unique words / total words)
- Используется для rubric.language

### Формула скоринга

```php
$score = max(
    round($simGood * 1.00),   // 100% веса для good
    round($simAvg * 0.66),    // 66% веса для average
    round($simBad * 0.33),    // 33% веса для bad
);
```

---

## Ограничения текущей реализации

1. **Только ExamExampleQuestion** - сервис работает с примерами, не с реальными Question
2. **Нет MCQ auto-scoring** - correct_answer в Question не используется
3. **LLM отключен** - `config('ai.evaluation.enable_llm')` не реализован
4. **Нет batch evaluation** - только один ответ за запрос
5. **Нет user_id** - оценки не привязаны к пользователям

---

## TODO: Доработки для production

### 1. MCQ Auto-scoring
```php
// Добавить в EvaluationService
public function evaluateMcq(Question $question, mixed $userAnswer): array
{
    $correct = $question->correct_answer;
    $isCorrect = $userAnswer === $correct;
    $points = $isCorrect ? $question->points : 0;

    return [
        'correct' => $isCorrect,
        'points' => $points,
        'max_points' => $question->points,
        'correct_answer' => $correct, // для показа после ответа
    ];
}
```

### 2. Привязка к Question модели
- Изменить `question_id` с ExamExampleQuestion на Question
- Или добавить отдельный параметр `real_question_id`

### 3. LLM-оценка (для writing/speaking)
- Включить через `AI_EVALUATION_LLM_ENABLED=true`
- Использовать GPT для глубокого анализа ответа
- Генерировать детальный feedback

### 4. Bulk Evaluation API
```
POST /api/evaluate/session
{
  "exam_id": "uuid",
  "answers": [
    {"question_id": 1, "answer": "A"},
    {"question_id": 2, "answer": "Some text..."},
    ...
  ]
}
```

### 5. User Sessions
- Модель `ExamSession` для хранения попыток
- Привязка Evaluation к user_id и session_id
- Статистика и прогресс пользователя

---

## Конфигурация

```env
# .env
AI_EVALUATION_LLM_ENABLED=false  # Включить LLM-оценку
AI_EVALUATION_MODEL=gpt-5-mini   # Модель для оценки
```

```php
// config/ai.php
'evaluation' => [
    'enable_llm' => env('AI_EVALUATION_LLM_ENABLED', false),
    'model' => env('AI_EVALUATION_MODEL', 'gpt-5-mini'),
],
```

---

## План: API для приёма ответов от мобильного приложения

### Вариант A: Единый эндпоинт (рекомендуется)

Один универсальный эндпоинт для всех типов ответов.

#### POST /api/answers/submit

```json
{
  "session_id": "uuid",           // ID сессии прохождения
  "question_id": 123,             // ID вопроса (Question)
  "answer": "A",                  // Ответ (string | array | object)
  "time_spent_sec": 45,           // Время на ответ (опционально)
  "attempt": 1                    // Номер попытки (опционально)
}
```

**Response (instant feedback mode):**
```json
{
  "ok": true,
  "answer_id": 456,
  "evaluation": {
    "correct": true,
    "points": 2,
    "max_points": 2,
    "feedback": "Correct!"
  }
}
```

**Response (exam mode - без feedback):**
```json
{
  "ok": true,
  "answer_id": 456,
  "saved": true
}
```

#### Формат answer по типам вопросов

| Тип | Формат answer | Пример |
|-----|---------------|--------|
| single_select | string | `"A"` или `"option_id_123"` |
| multi_select | array | `["A", "C"]` |
| true_false | boolean | `true` |
| yes_no_ng | string | `"yes"` / `"no"` / `"not_given"` |
| fill_blank | object | `{"blank_1": "answer", "blank_2": "text"}` |
| matching | object | `{"1": "A", "2": "C", "3": "B"}` |
| ordering | array | `["C", "A", "B", "D"]` |
| writing_prompt | string | `"Full text answer..."` |
| speaking_prompt | string (url) | `"https://storage.../audio.mp3"` |

---

### Вариант B: Отдельные эндпоинты по типам

Если нужна разная логика обработки:

```
POST /api/answers/mcq          # single_select, multi_select, true_false
POST /api/answers/text         # writing_prompt, fill_blank
POST /api/answers/audio        # speaking_prompt (upload file)
POST /api/answers/matching     # matching, ordering
```

**Плюсы:** Чёткая валидация, разные rate limits
**Минусы:** Больше кода, сложнее для клиента

---

### Сессии прохождения экзамена

#### Модель ExamSession

```php
// Migration
Schema::create('exam_sessions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('exam_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('mode')->default('practice'); // practice | exam | review
    $table->json('settings')->nullable();        // time_limit, shuffle, etc.
    $table->timestamp('started_at');
    $table->timestamp('finished_at')->nullable();
    $table->json('result')->nullable();          // итоговый результат
    $table->timestamps();
});
```

#### Модель Answer

```php
// Migration
Schema::create('answers', function (Blueprint $table) {
    $table->id();
    $table->foreignUuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
    $table->foreignId('question_id')->constrained()->cascadeOnDelete();
    $table->json('answer');                      // ответ пользователя
    $table->json('evaluation')->nullable();      // результат оценки
    $table->integer('time_spent_sec')->nullable();
    $table->tinyInteger('attempt')->default(1);
    $table->timestamps();

    $table->unique(['session_id', 'question_id', 'attempt']);
});
```

---

### API Flow: Полный цикл

```
┌─────────────────────────────────────────────────────────────────┐
│                        MOBILE APP                                │
└─────────────────────────────────────────────────────────────────┘
                              │
        1. Начать сессию      │
        ──────────────────────┼──────────────────────────────────►
                              │    POST /api/sessions/start
                              │    { exam_id, mode: "practice" }
                              │
                              │◄─── { session_id, questions: [...] }
                              │
        2. Отправить ответ    │
        ──────────────────────┼──────────────────────────────────►
                              │    POST /api/answers/submit
                              │    { session_id, question_id, answer }
                              │
                              │◄─── { evaluation: { correct, points } }
                              │
        3. Следующий ответ    │
              ...             │
                              │
        4. Завершить сессию   │
        ──────────────────────┼──────────────────────────────────►
                              │    POST /api/sessions/{id}/finish
                              │
                              │◄─── { total_score, breakdown, time }
```

---

### Эндпоинты для реализации

| Метод | Путь | Описание |
|-------|------|----------|
| POST | `/api/sessions/start` | Начать сессию прохождения |
| GET | `/api/sessions/{id}` | Получить состояние сессии |
| POST | `/api/answers/submit` | Отправить ответ на вопрос |
| GET | `/api/sessions/{id}/answers` | Все ответы сессии |
| POST | `/api/sessions/{id}/finish` | Завершить и получить результат |
| GET | `/api/sessions/{id}/result` | Детальный результат (после finish) |

---

### Режимы работы (mode)

| Mode | Instant Feedback | Time Limit | Retry | Show Correct |
|------|------------------|------------|-------|--------------|
| `practice` | ✅ Сразу | ❌ Нет | ✅ Да | ✅ Да |
| `exam` | ❌ В конце | ✅ Да | ❌ Нет | ❌ Нет |
| `review` | ✅ Сразу | ❌ Нет | ✅ Да | ✅ Да |

---

### План реализации

**Этап 1: Базовая инфраструктура**
- [ ] Миграции: exam_sessions, answers
- [ ] Модели: ExamSession, Answer
- [ ] AnswerController с базовыми эндпоинтами

**Этап 2: Оценка MCQ**
- [ ] EvaluationService::evaluateMcq()
- [ ] Автоматическая оценка при submit (practice mode)

**Этап 3: Сессии**
- [ ] SessionController: start, finish, result
- [ ] Подсчёт итогов при завершении

**Этап 4: Текстовые ответы**
- [ ] Интеграция существующего EvaluationService
- [ ] Async оценка для writing/speaking

**Этап 5: Аудио ответы**
- [ ] Upload endpoint для speaking_prompt
- [ ] Хранение в storage
- [ ] Транскрипция (опционально)
