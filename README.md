# ExamHelp — Stage 1 (README)

> Быстрый старт, проверка окружения, основные эндпоинты и сценарии тестирования.  
> Актуально на: **24.10.2025** (Европа/Варшава)

---

## 1) Что это и что уже работает

**ExamHelp** — Laravel-приложение с очередями, Nova-админкой и AI‑пайплайном для исследования экзамена и построения его структуры.  
Stage 1 покрывает:

- Docker-инфраструктуру (Nginx + PHP-FPM, **MySQL**, Redis, queue-worker, scheduler, Mailpit).
- Nova ресурсы: Exams/Categories/Documents/GenerationTask/GenerationLog и действия для запуска пайплайна.
- API для создания экзамена, запуска ресёрча, получения статуса задачи и финальной структуры.
- Оценку текстовых ответов (`/api/evaluate/text`).

> На этапе Stage 1 **ещё в работе**: `POST /api/evaluate/audio` и полноценный учёт токенов как отдельной сущности (`TokenUsage`).

---

## 2) Предварительные требования

- Docker + Docker Compose
- (опционально) `make`
- Свободные локальные порты: **8080** (Nginx), **3306** (MySQL), **6379** (Redis), **8025/1025** (Mailpit)
- Утилиты для тестов: `curl`, `jq`

> Если порты заняты — см. раздел **Переопределение портов** ниже.

---

## 3) Установка и запуск

```bash
git clone <repo-url> && cd <repo>
cp .env.example .env
```

### 3.1 Минимальная конфигурация `.env` (MySQL + Redis)

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=examhelp
DB_USERNAME=app
DB_PASSWORD=app

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_DRIVER=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025

# Провайдер ИИ: openai | mock
AI_PROVIDER=mock
# Если AI_PROVIDER=openai — добавить ключ:
GPT5_API_KEY=your-api-key-here
AI_MODEL=gpt-4o-mini
```

### 3.2 Поднятие окружения

```bash
# через make (если есть)
make up

# или напрямую:
docker compose up -d --build
```

### 3.3 Первичная инициализация

```bash
# Права на каталоги (Linux/WSL/macOS)
docker compose exec -u root app sh -lc '
  mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache &&
  chown -R www-data:www-data storage bootstrap/cache &&
  find storage bootstrap/cache -type d -exec chmod 775 {} \; &&
  find storage bootstrap/cache -type f -exec chmod 664 {} \;
'

# Генерация ключа и оптимизация
docker compose exec app composer install -o --no-interaction
docker compose exec app php artisan key:generate
docker compose exec app php artisan storage:link || true
docker compose exec app php artisan migrate

# (опционально) Сид админа для Nova (admin@example.com / password)
docker compose exec app php artisan db:seed --class=AdminUserSeeder
```

### 3.4 Проверка, что всё работает

- Приложение: http://localhost:8080  
- Health: http://localhost:8080/health  
- Nova: http://localhost:8080/nova (admin@example.com / **password**)  
- Mailpit: http://localhost:8025

Очередь должна обрабатывать задания:  
```bash
docker compose ps queue-worker
docker compose logs queue-worker -f
```

---

## 4) Быстрый старт по API (curl)

### 4.1 Получить токен доступа

Через Tinker:
```bash
docker compose exec app php artisan tinker <<'PHP'
$user = \App\Models\User::first() ?? \App\Models\User::factory()->create([
  'email' => 'admin@example.com',
  'name' => 'Admin',
  'password' => bcrypt('password'),
]);
echo $user->createToken('local')->plainTextToken.PHP_EOL;
PHP
```

Сохранить:
```bash
export API_TOKEN="1|your-token-here"
export BASE_URL="http://localhost:8080"
```

### 4.2 Создать экзамен

```bash
curl -s -X POST "$BASE_URL/api/exams" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"IELTS Academic Test","level":"B2"}' | jq .
```

Сохранить `EXAM_ID`:
```bash
export EXAM_ID=$(curl -s -X POST "$BASE_URL/api/exams" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"English Exam","level":"B2"}' | jq -r '.data.id')
echo "EXAM_ID=$EXAM_ID"
```

### 4.3 Запустить ресёрч (Stage 1: Identity)

```bash
RESP=$(curl -s -X POST "$BASE_URL/api/exams/$EXAM_ID/research" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_input": {
      "language": "English",
      "where": {"country": "PL","city": "Warsaw","modality": "test_center"},
      "target": {"level": "B2","score":"6.5"},
      "exam_name": "IELTS Academic"
    }
  }')
echo "$RESP" | jq .
export TASK_ID=$(echo "$RESP" | jq -r '.task_id')
```

### 4.4 Проверить статус задачи

```bash
curl -s "$BASE_URL/api/tasks/$TASK_ID" -H "Authorization: Bearer $API_TOKEN" | jq .
```

Если статус `pending_confirmation` и `identity.hold=true` — подтвердите:

```bash
curl -s -X POST "$BASE_URL/api/exams/$EXAM_ID/research/$TASK_ID/confirm-identity" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"confirmed":true,"notes":"OK"}' | jq .
```

### 4.5 Получить финальную структуру экзамена

```bash
sleep 15
curl -s "$BASE_URL/api/exams/$EXAM_ID/structure" \
  -H "Authorization: Bearer $API_TOKEN" | jq .
```

### 4.6 (Опционально) Ресёрч без подтверждения

```bash
curl -s -X POST "$BASE_URL/api/exams/$EXAM_ID/research" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_input": {"exam_name":"TOEFL iBT","language":"English","where":{"country":"US"},"target":{"score":"90"}},
    "without_confirmation": true
  }' | jq .
```

### 4.7 Оценка текста

```bash
curl -s -X POST "$BASE_URL/api/evaluate/text" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "exam_id": "'"$EXAM_ID"'",
    "category_id": 1,
    "question_id": 1,
    "answer_text": "I usually wake up at seven and go to work by bus."
  }' | jq .
```

> **TODO (Stage 1 доп.):** `/api/evaluate/audio` (multipart) — заглушка ASR → текст → оценка.

---

## 5) Тестирование сценариев

Для полного покрытия Stage 1 используйте файл: **`TESTING_ALL_SCENARIOS_ru.md`** (лежит в репозитории).  
Там описаны 5 сценариев: базовый флоу, без подтверждения, загрузка документа, отклонение идентичности, неполный ввод с уточнениями — с примерами curl/Postman и проверками в Nova/БД.

Запуск всех тестов:
```bash
docker compose exec app php artisan test --verbose
```

> Если очередь «молчит» — проверьте контейнер `queue-worker` и логи (см. раздел ниже).

---

## 6) Полезные команды

```bash
# Docker
docker compose up -d --build         # поднять
docker compose down -v               # остановить и очистить тома
docker compose logs -f app           # логи приложения
docker compose logs -f queue-worker  # логи воркера очередей

# Artisan
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=AdminUserSeeder
docker compose exec app php artisan optimize
docker compose exec app php artisan route:clear && docker compose exec app php artisan view:clear

# Composer
docker compose exec app composer install -o
docker compose exec app composer dump-autoload -o
```

---

## 7) Nova

- URL: **http://localhost:8080/nova**
- Логин: **admin@example.com**
- Пароль: **password** (после `AdminUserSeeder`)

Панели:
- **🔍 Stage 1: Identity Verification** — статус, confidence, canonical данные.
- **📚 Stage 2: Exam Structure** — секции/шаги, итоговая структура.
- **🧰 Generation Logs** — подробные логи стадий (prompt/completion tokens и пр.).

---

## 8) Устранение неполадок

### Очередь не обрабатывает задачи
```bash
docker compose ps queue-worker
docker compose logs queue-worker --tail=100
docker compose restart queue-worker
# разово отработать задачи в контейнере app:
docker compose exec app php artisan queue:work --stop-when-empty
```

### Документ загружен, но текст не извлёкся
- Убедитесь, что установлен инструмент извлечения (в образе).
- Проверьте задачу извлечения в логах `generation_logs`/консоль `queue-worker`.

### `Not Found` при обращении к API
- Проверьте корректность `APP_URL` и портов.
- Пересоберите контейнеры (`docker compose up -d --build`) и очистите кэш роутов:
  ```bash
  docker compose exec app php artisan route:clear && docker compose exec app php artisan optimize
  ```

---

## 9) Переопределение портов (пример)

Создайте рядом `docker-compose.override.yml`:
```yaml
services:
  nginx:
    ports: ["18080:8080"]
  mysql:
    ports: ["13306:3306"]
  redis:
    ports: ["16379:6379"]
  mailpit:
    ports:
      - "18025:8025"
      - "11025:1025"
```

В таком случае приложение будет доступно на `http://localhost:18080` (обновите `APP_URL`).

---

## 10) Статус Stage 1 и дальше

**Сделано:** инфраструктура, Nova, API для ресёрча/структуры/текста, загрузка документов, логи и ретраи, сценарии тестирования.  
**Осталось закрыть:**

1. `POST /api/evaluate/audio` (multipart; ASR-заглушка → текст → оценка).
2. Учёт токенов как отдельной сущности (`TokenUsage`) + агрегации по экзамену/задаче/пользователю.
3. Обновление скриншотов/скриптов для Nova (если UI менялся) — по мере правок.

---

## 11) Лицензия / вклад

Добавляйте Issue/PR с чётким описанием шага/сценария, ссылкой на соответствующий раздел в `TESTING_ALL_SCENARIOS_ru.md`, и скрином/логами подтверждения.
