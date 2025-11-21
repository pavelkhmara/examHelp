# Mobile API

API для интеграции с мобильным приложением.

## Endpoints

Все endpoints имеют префикс `/api/mobile/`.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/exams/{exam}/export` | Экспорт экзамена в формат мобильного приложения |
| POST | `/sessions/start` | Начать сессию прохождения |
| GET | `/sessions/{session}` | Статус сессии |
| POST | `/sessions/{session}/finish` | Завершить сессию |
| GET | `/sessions/{session}/answers` | Все ответы сессии |
| GET | `/sessions/{session}/result` | Детальный результат |
| POST | `/answers/submit` | Отправить ответ на вопрос |
| GET | `/answers/{answer}` | Информация об ответе |
| GET | `/sessions/{session}/recommendations` | Рекомендации по результатам |
| POST | `/sessions/{session}/recommendations/regenerate` | Перегенерировать рекомендации |
| GET | `/users/{userId}/recommendations` | Агрегированные рекомендации пользователя |

---

## Экспорт экзамена

### GET /api/mobile/exams/{exam}/export

Экспортирует экзамен в формат, совместимый с мобильным приложением.

**Response:**
```json
{
  "data": {
    "id": "uuid",
    "name": "IELTS Academic",
    "exam_name": "IELTS Academic",
    "exam_full_name": "International English Language Testing System",
    "exam_organization": "British Council",
    "language": "en",
    "level": "B2-C1",
    "categories": [
      {
        "id": 1,
        "app_id": "uuid",
        "name": "Listening",
        "questions_qty_in_mock": 40,
        "sort_order": 1
      }
    ],
    "questions": [
      {
        "id": 123,
        "app_id": "uuid",
        "category_id": 1,
        "reference": "Q1",
        "difficulty": 2,
        "question": [
          {"type": "text", "content": "Question text..."}
        ],
        "explanation": [
          {"type": "text", "content": "Explanation..."}
        ],
        "answer": [
          {"type": "text", "content": "Correct answer"}
        ],
        "distractor1": [{"type": "text", "content": "Wrong answer 1"}],
        "distractor2": [{"type": "text", "content": "Wrong answer 2"}],
        "distractor3": [{"type": "text", "content": "Wrong answer 3"}]
      }
    ]
  }
}
```

---

## Сессии

### POST /api/mobile/sessions/start

Начать новую сессию прохождения экзамена.

**Request:**
```json
{
  "exam_id": "uuid",
  "user_id": "user123",
  "device_id": "device-abc",
  "mode": "practice",
  "settings": {
    "category_ids": [1, 2],
    "time_limit_sec": 3600,
    "shuffle": true,
    "questions_count": 20
  }
}
```

**Response:**
```json
{
  "ok": true,
  "session_id": "uuid",
  "mode": "practice",
  "started_at": "2025-05-21T10:00:00Z",
  "questions_count": 20,
  "question_ids": [1, 2, 3, ...]
}
```

### POST /api/mobile/sessions/{session}/finish

Завершить сессию и получить результат.

**Response:**
```json
{
  "ok": true,
  "session_id": "uuid",
  "finished_at": "2025-05-21T11:00:00Z",
  "result": {
    "total_points": 18,
    "max_points": 20,
    "percentage": 90.0,
    "correct": 18,
    "incorrect": 2,
    "total_questions": 20
  },
  "recommendations_count": 3
}
```

### GET /api/mobile/sessions/{session}/result

Детальный результат с разбивкой по категориям.

**Response:**
```json
{
  "ok": true,
  "session_id": "uuid",
  "result": {...},
  "breakdown": [
    {
      "category_id": 1,
      "category_name": "Listening",
      "correct": 8,
      "total": 10,
      "percentage": 80.0
    }
  ],
  "finished_at": "2025-05-21T11:00:00Z"
}
```

---

## Ответы

### POST /api/mobile/answers/submit

Отправить ответ на вопрос.

**Request:**
```json
{
  "session_id": "uuid",
  "question_id": 123,
  "answer": "A",
  "time_spent_sec": 45,
  "attempt": 1
}
```

**Форматы answer по типам вопросов:**

| Тип | Формат | Пример |
|-----|--------|--------|
| single_select | string | `"A"` |
| multi_select | array | `["A", "C"]` |
| true_false | boolean | `true` |
| yes_no_ng | string | `"yes"` / `"no"` / `"not_given"` |
| fill_blank | object | `{"blank_1": "answer"}` |
| matching | object | `{"1": "A", "2": "B"}` |
| ordering | array | `["C", "A", "B"]` |
| writing_prompt | string | `"Full text..."` |

**Response (practice mode):**
```json
{
  "ok": true,
  "answer_id": 456,
  "saved": true,
  "evaluation": {
    "is_correct": true,
    "points_earned": 1,
    "points_possible": 1,
    "feedback": "Correct!",
    "correct_answer": "A"
  }
}
```

**Response (exam mode):**
```json
{
  "ok": true,
  "answer_id": 456,
  "saved": true
}
```

---

## Рекомендации

### GET /api/mobile/sessions/{session}/recommendations

Получить рекомендации по результатам сессии.

**Response:**
```json
{
  "ok": true,
  "session_id": "uuid",
  "total": 5,
  "recommendations": [
    {
      "id": 1,
      "type": "weakness",
      "category": "Grammar",
      "category_id": 2,
      "priority": "high",
      "title": "Weak area: Grammar",
      "description": "You scored 40% (4/10) in this category...",
      "data": {
        "accuracy": 0.4,
        "correct": 4,
        "total": 10,
        "missed_questions": [5, 8, 12, 15, 18, 22]
      }
    }
  ],
  "grouped": {
    "weaknesses": [...],
    "strengths": [...],
    "suggestions": [...],
    "resources": [...]
  }
}
```

### GET /api/mobile/users/{userId}/recommendations

Агрегированные рекомендации пользователя (по всем сессиям).

**Response:**
```json
{
  "ok": true,
  "user_id": "user123",
  "sessions_analyzed": 5,
  "persistent_weaknesses": [
    {
      "category": "Grammar",
      "category_id": 2,
      "count": 4,
      "avg_accuracy": 0.35
    }
  ],
  "latest_session": {
    "id": "uuid",
    "finished_at": "2025-05-21T11:00:00Z",
    "result": {...}
  }
}
```

---

## Режимы сессии

| Mode | Instant Feedback | Time Limit | Retry | Show Correct |
|------|------------------|------------|-------|--------------|
| `practice` | Сразу после ответа | Нет | Да | Да |
| `exam` | Только в конце | Да | Нет | Нет |
| `review` | Сразу после ответа | Нет | Да | Да |

---

## Модели данных

### ExamSession

```
exam_sessions
├── id (UUID)
├── exam_id (FK)
├── user_id (string, nullable)
├── device_id (string, nullable)
├── mode (practice|exam|review)
├── settings (JSON)
├── started_at
├── finished_at
├── result (JSON)
└── timestamps
```

### Answer

```
answers
├── id
├── session_id (FK)
├── question_id (FK)
├── answer (JSON)
├── evaluation (JSON)
├── is_correct (bool)
├── points_earned
├── points_possible
├── time_spent_sec
├── attempt
└── timestamps
```

### Recommendation

```
recommendations
├── id
├── session_id (FK)
├── type (weakness|strength|suggestion|resource)
├── category (string)
├── category_id (FK, nullable)
├── priority (high|medium|low)
├── title
├── description
├── data (JSON)
└── timestamps
```

---

## Сервисы

| Сервис | Путь | Назначение |
|--------|------|------------|
| AnswerEvaluationService | `app/Services/LanguageApp/AnswerEvaluationService.php` | Оценка ответов (MCQ, fill-blank, matching, etc.) |
| RecommendationService | `app/Services/LanguageApp/RecommendationService.php` | Генерация рекомендаций по результатам |

---

## TODO

- [ ] Аутентификация мобильного приложения (API keys / JWT)
- [ ] Rate limiting для endpoints
- [ ] LLM-оценка для writing/speaking
- [ ] Кэширование экспорта экзамена
- [ ] Websocket для real-time обновлений
