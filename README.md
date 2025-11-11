# ExamHelp — README

> AI-powered exam preparation platform с исследованием структуры экзаменов через LLM
> Актуально на: **11.11.2025**

---

## 1) Что это и что уже работает

**ExamHelp** — Laravel-приложение с параллельной обработкой очередей, Nova-админкой и AI‑пайплайном (GPT-5) для автоматического исследования экзаменов и построения их структуры.

### Реализовано:

- **Docker-инфраструктура**: Nginx + PHP-FPM, MySQL 8.0, Redis 7, **3 параллельных queue workers**, scheduler, Mailpit
- **Nova админка**: Resources для Exams/Categories/Documents/GenerationTask/GenerationLog с Actions и динамическими Cards
- **AI Research Pipeline**: Многостадийное исследование экзамена (Identity → Confidence Boost → Overview → Sanity → Examples)
- **REST API**: Создание экзамена, запуск research, polling статуса, получение структуры, оценка текстовых ответов
- **Identity Verification**: Верификация идентичности экзамена с confidence scoring и interactive clarification
- **Document Processing**: Загрузка PDF/DOCX с текстовой экстракцией (pdftotext + опциональный OCR)
- **Rate Limiting**: Защита от throttling OpenAI API с Redis-based sliding window
- **Activity Timeline**: Real-time прогресс выполнения задач с heartbeat мониторингом
- **Context Pack**: Утилита для генерации полного контекста проекта (`make ctx`)

### В разработке:

- `POST /api/evaluate/audio` (ASR → оценка речи)
- Учёт токенов как отдельной сущности (`TokenUsage`) с агрегацией

---

## 2) Предварительные требования

- **Docker** 20.10+ и **Docker Compose** v2+
- **make** (рекомендуется для удобных команд)
- **Свободные порты**:
  - **8080** — Nginx (веб-сервер)
  - **3306** — MySQL
  - **56379** — Redis
  - **8025** — Mailpit UI (почта)
  - **1025** — Mailpit SMTP
- **Утилиты** (для тестирования): `curl`, `jq`, `git`
- **OpenAI API Key** (GPT-5) для production или mock-провайдер для тестов

> **Продакшн**: Приложение развернуто в `/opt/examhelp/examHelp`
> Если порты заняты — см. раздел **Переопределение портов** ниже.

---

## 3) Установка и запуск

```bash
# Локальная разработка
git clone <repo-url> && cd examHelp
cp .env.example .env

# Продакшн
cd /opt/examhelp/examHelp
```

### 3.1 Минимальная конфигурация `.env`

```dotenv
APP_ENV=local  # или production
APP_DEBUG=true  # false для продакшна
APP_URL=http://localhost:8080  # или ваш домен
APP_KEY=  # генерируется через php artisan key:generate

# MySQL
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=examhelp
DB_USERNAME=app
DB_PASSWORD=app

# Redis (queue, cache, sessions)
REDIS_CLIENT=predis  # или phpredis
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Mail (local - Mailpit)
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@examhelp.local
MAIL_FROM_NAME="ExamHelp"

# AI Provider - GPT-5 (OpenAI)
AI_PROVIDER=openai  # или mock для тестов
AI_MODEL=gpt-5-mini  # Основная модель (identity, confidence boost)
AI_MODEL_THINKING=gpt-5  # Модель для сложных задач (overview, structure)
GPT5_API_KEY=your-openai-api-key-here
GPT5_BASE_URL=https://api.openai.com/v1
AI_REQUEST_TIMEOUT=60
AI_JSON_STRICT=1

# Rate Limiting (защита от OpenAI throttling)
AI_RATE_LIMIT_ENABLED=true
AI_RATE_LIMIT_RPM=60  # requests per minute
AI_RATE_LIMIT_RETRIES=3
AI_RATE_LIMIT_RETRY_DELAY_MS=1000

# Async AI (опционально, экспериментально)
AI_ASYNC_ENABLED=false
AI_PARALLEL_MAX=5

# Document Processing
DOC_MAX_MB=20
DOC_EXTRACTOR_FAKE=false
PDFTOTEXT_BIN=/usr/bin/pdftotext
DOC_OCR_ENABLED=false
TESSERACT_BIN=/usr/bin/tesseract
DOC_OCR_LANGS=eng
INTERNAL_API_BASE=http://nginx
```

> **ВАЖНО**: Для продакшна обязательно установите `AI_PROVIDER=openai` и укажите реальный OpenAI API Key с доступом к GPT-5 моделям.

### 3.2 Поднятие окружения

```bash
# Через make (рекомендуется)
make up

# Или напрямую
docker compose up -d --build

# Проверить статус контейнеров
docker compose ps

# Должны быть запущены:
# - app (PHP-FPM)
# - nginx
# - mysql
# - redis
# - queue-worker-1, queue-worker-2, queue-worker-3 (3 параллельных воркера)
# - scheduler
# - mailpit
```

### 3.3 Первичная инициализация

```bash
# 1. Установка зависимостей и прав (только при первом запуске)
docker compose exec -u root app sh -lc '
  mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache &&
  chown -R www-data:www-data storage bootstrap/cache &&
  find storage bootstrap/cache -type d -exec chmod 775 {} \; &&
  find storage bootstrap/cache -type f -exec chmod 664 {} \;
'

# 2. Composer зависимости (включая predis для Redis)
docker compose exec app composer require predis/predis:^2.0 -W
docker compose exec app composer install -o --no-interaction

# 3. Генерация ключа приложения
docker compose exec app php artisan key:generate

# 4. Создание symlink для storage
docker compose exec app php artisan storage:link || true

# 5. Миграции базы данных
docker compose exec app php artisan migrate

# 6. (Опционально) Seed админа для Nova
docker compose exec app php artisan db:seed --class=AdminUserSeeder
# Логин: admin@example.com
# Пароль: password
```

### 3.4 Проверка работоспособности

**URL сервисов:**
- Приложение: http://localhost:8080
- Health check: http://localhost:8080/health (должен вернуть `{"status":"ok"}`)
- Nova админка: http://localhost:8080/nova
- Mailpit UI: http://localhost:8025

**Проверка очередей (3 воркера):**
```bash
# Статус всех воркеров
docker compose ps | grep queue-worker

# Должен показать:
# examhelp-queue-worker-1   running
# examhelp-queue-worker-2   running
# examhelp-queue-worker-3   running

# Логи воркеров в реальном времени
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f

# Или отдельно каждого
docker compose logs queue-worker-1 -f
```

**Проверка scheduler:**
```bash
docker compose logs scheduler -f
# Должен показывать запуск каждую минуту
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

## 6) Управление приложением (команды для продакшна)

### 6.1 Make команды (рекомендуется)

```bash
# === Запуск и остановка ===
make up              # Запустить все контейнеры
make down            # Остановить и удалить контейнеры + volumes
make init            # Полная инициализация: pull + up + install + migrate + seed

# === Разработка и деплой ===
make refresh         # Полный цикл: cache-clear + dump-autoload + worker-restart
make fast-refresh    # Быстрый цикл: только cache + worker-restart (без composer)

# === База данных ===
make migrate         # Запустить миграции
make seed            # Запустить seeders

# === Тестирование ===
make test            # Запустить PHPUnit тесты
make cs              # Проверить code style (Pint)
make stan            # Статический анализ (PHPStan)
make lint            # Проверить форматирование (без изменений)

# === Очереди ===
make queue           # Запустить queue worker вручную (foreground)
make worker-restart  # Рестарт всех queue workers
make logs            # Логи приложения

# === Доступ к контейнерам ===
make bash            # Bash в контейнере app
make app-shell       # Shell в контейнере app
make queue-shell     # Shell в queue-worker

# === Context Pack (генерация документации) ===
make ctx             # Полный контекст проекта (все секции)
make ctx-models      # Только модели с relationships
make ctx-db          # Только схема БД
make ctx-models-db   # Модели + БД
make ctx-nova        # Nova resources
make ctx-file FILE=app/Services/MyService.php  # Контекст одного файла
```

### 6.2 Docker Compose команды (прямое использование)

```bash
# === Управление контейнерами ===
docker compose up -d --build         # Запустить с пересборкой
docker compose down                  # Остановить без удаления volumes
docker compose down -v               # Остановить + очистить volumes (БД!)
docker compose restart               # Перезапустить все контейнеры
docker compose restart app           # Перезапустить один контейнер

# === Логи ===
docker compose logs -f app           # Логи приложения
docker compose logs -f nginx         # Логи веб-сервера
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f  # Все воркеры
docker compose logs scheduler -f     # Логи планировщика
docker compose logs --tail=100 app   # Последние 100 строк

# === Статус ===
docker compose ps                    # Список контейнеров
docker compose ps | grep queue       # Только queue workers
docker compose top                   # Процессы внутри контейнеров
```

### 6.3 Artisan команды

```bash
# === Миграции ===
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh --seed  # DANGER: удаляет все данные!
docker compose exec app php artisan migrate:status

# === Кеш и оптимизация ===
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear
docker compose exec app php artisan optimize:clear  # Очистить все кеши
docker compose exec app php artisan optimize       # Оптимизировать для продакшна

# === Очереди ===
docker compose exec app php artisan queue:work --tries=1 --queue=default
docker compose exec app php artisan queue:restart  # Перезапустить воркеры
docker compose exec app php artisan queue:failed   # Список failed jobs
docker compose exec app php artisan queue:retry all  # Повтор всех failed jobs

# === Tinker (REPL) ===
docker compose exec app php artisan tinker
docker compose exec app php artisan tinker --execute="App\Models\User::count()"

# === Информация ===
docker compose exec app php artisan route:list
docker compose exec app php artisan about
docker compose exec app php artisan env
```

### 6.4 Composer команды

```bash
docker compose exec app composer install -o --no-interaction
docker compose exec app composer update
docker compose exec app composer dump-autoload -o
docker compose exec app composer require package/name
docker compose exec app composer show  # Список установленных пакетов

# Code quality
docker compose exec app composer lint  # Проверка форматирования
docker compose exec app composer fix   # Автоисправление форматирования
```

### 6.5 Команды для продакшн-сервера (`/opt/examhelp/examHelp`)

```bash
# Переход в рабочую директорию
cd /opt/examhelp/examHelp

# Обновление кода из git
git pull origin master
make refresh  # Очистить кеши + перезапустить воркеры

# Применение миграций
make migrate

# Перезапуск контейнеров (при изменении .env или docker-compose.yml)
docker compose down && docker compose up -d --build

# Мониторинг воркеров
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f --tail=50

# Проверка задач в Redis
docker compose exec redis redis-cli LLEN queues:default  # Количество задач в очереди
docker compose exec redis redis-cli LRANGE queues:default 0 -1  # Список задач
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

## 8) Мониторинг и отладка (Production)

### 8.1 Проверка состояния системы

```bash
# Статус всех контейнеров
docker compose ps

# Health check API
curl http://localhost:8080/health

# Статус базы данных
docker compose exec app php artisan migrate:status

# Проверка подключения к Redis
docker compose exec redis redis-cli ping
# Должен вернуть: PONG
```

### 8.2 Мониторинг очередей

```bash
# === Статус воркеров ===
docker compose ps | grep queue-worker

# === Логи воркеров (real-time) ===
# Все 3 воркера
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f

# С фильтром по тексту
docker compose logs queue-worker-1 -f | grep "ERROR\|Processing"

# === Задачи в Redis ===
docker compose exec redis redis-cli
> LLEN queues:default           # Количество задач в очереди
> LRANGE queues:default 0 -1    # Список задач
> KEYS *queue*                  # Все ключи очередей

# === Failed Jobs ===
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry all

# === Rate Limiting ===
docker compose exec redis redis-cli
> ZCARD ai_rate_limit:openai           # Количество запросов в текущем окне
> ZRANGE ai_rate_limit:openai 0 -1 WITHSCORES  # Детали запросов
```

### 8.3 Производительность и метрики

```bash
# === Процессы внутри контейнеров ===
docker compose top

# === Использование ресурсов ===
docker stats  # Все контейнеры

# === Размер базы данных ===
docker compose exec mysql mysql -uapp -papp examhelp -e "
SELECT table_schema AS 'Database',
       ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.tables
WHERE table_schema = 'examhelp'
GROUP BY table_schema;"

# === Количество записей ===
docker compose exec app php artisan tinker --execute="
echo 'Exams: ' . App\Models\Exam::count() . PHP_EOL;
echo 'Tasks: ' . App\Models\GenerationTask::count() . PHP_EOL;
echo 'Logs: ' . App\Models\GenerationLog::count() . PHP_EOL;
"
```

### 8.4 Логи и отладка

```bash
# === Laravel логи ===
docker compose exec app tail -f storage/logs/laravel.log

# === Nginx логи ===
docker compose logs nginx -f
docker compose exec nginx tail -f /var/log/nginx/access.log
docker compose exec nginx tail -f /var/log/nginx/error.log

# === Поиск ошибок ===
docker compose logs app | grep -i "error\|exception\|fatal"
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 | grep -i "error\|failed"

# === Отладка конкретной задачи ===
docker compose exec app php artisan tinker
>>> $task = \App\Models\GenerationTask::find(123);
>>> $task->status;
>>> $task->result;
>>> $task->activities;
```

### 8.5 Типовые проблемы и решения

#### Проблема: Очередь не обрабатывает задачи

**Диагностика:**
```bash
# 1. Проверить статус воркеров
docker compose ps | grep queue-worker

# 2. Посмотреть логи
docker compose logs queue-worker-1 --tail=50

# 3. Проверить подключение к Redis
docker compose exec redis redis-cli ping
```

**Решение:**
```bash
# Перезапустить воркеры
docker compose restart queue-worker-1 queue-worker-2 queue-worker-3

# Или рестартовать через artisan
make worker-restart

# Если не помогло - пересоздать контейнеры
docker compose up -d --force-recreate queue-worker-1 queue-worker-2 queue-worker-3
```

#### Проблема: Rate limit от OpenAI

**Симптомы:**
- В логах: `OpenAI rate limit exceeded`
- Джобы падают с ошибкой 429

**Решение:**
```bash
# 1. Проверить текущий лимит
docker compose exec redis redis-cli ZCARD ai_rate_limit:openai

# 2. Увеличить лимит в .env
nano .env
# Изменить:
AI_RATE_LIMIT_RPM=100  # было 60
AI_RATE_LIMIT_RETRIES=5  # было 3

# 3. Перезапустить воркеры
make worker-restart
```

#### Проблема: Документ загружен, но текст не извлёкся

**Диагностика:**
```bash
# Проверить, установлен ли pdftotext
docker compose exec app which pdftotext

# Проверить логи extraction
docker compose exec app php artisan tinker --execute="
\App\Models\ExamDocument::where('extracted_text', null)->get(['id', 'filename']);
"
```

**Решение:**
```bash
# Переустановить документ через API или Nova
# Или вручную запустить экстракцию:
docker compose exec app php artisan tinker --execute="
\$doc = \App\Models\ExamDocument::find(123);
\$extractor = app(\App\Services\DocumentExtractor::class);
\$extractor->extract(\$doc);
"
```

#### Проблема: `Not Found` при обращении к API

**Диагностика:**
```bash
# Проверить роуты
docker compose exec app php artisan route:list | grep api

# Проверить APP_URL
docker compose exec app php artisan env | grep APP_URL
```

**Решение:**
```bash
# Очистить кеши
make cache-clear

# Или вручную
docker compose exec app php artisan route:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan optimize

# Перезапустить контейнеры
docker compose restart app nginx
```

#### Проблема: Задачи зависают (stalled tasks)

**Диагностика:**
```bash
# Найти зависшие задачи (без heartbeat > 10 мин)
docker compose exec app php artisan tinker --execute="
\App\Models\GenerationTask::where('status', 'running')
    ->where('heartbeat_at', '<', now()->subMinutes(10))
    ->get(['id', 'type', 'heartbeat_at', 'updated_at']);
"
```

**Решение:**
```bash
# Через Nova: открыть Exam → найти StalledTaskCard → Cancel and Restart

# Или вручную через tinker:
docker compose exec app php artisan tinker --execute="
\$task = \App\Models\GenerationTask::find(123);
\$task->update(['status' => 'cancelled', 'error' => 'Stalled - manually cancelled']);
"
```

---

## 9) Переопределение портов

Если стандартные порты заняты, создайте `docker-compose.override.yml` в корне проекта:

```yaml
services:
  nginx:
    ports: ["18080:80"]  # Вместо 8080
  mysql:
    ports: ["13306:3306"]  # Вместо 3306
  redis:
    ports: ["58379:6379"]  # Вместо 56379
  mailpit:
    ports:
      - "18025:8025"  # Вместо 8025
      - "11025:1025"  # Вместо 1025
```

После изменения портов:
1. Обновите `APP_URL` в `.env`
2. Перезапустите контейнеры: `docker compose down && docker compose up -d`

---

## 10) Производительность и масштабирование

### 10.1 Параллельная обработка очередей

Приложение использует **3 параллельных queue workers** для одновременной обработки нескольких экзаменов:

```
queue-worker-1  ──→  Exam #1 (4 мин)  ┐
queue-worker-2  ──→  Exam #2 (4 мин)  ├─→  Итого: ~5 мин (вместо 20 мин)
queue-worker-3  ──→  Exam #3 (4 мин)  ┘
```

**Производительность:**
- **До оптимизации**: 1 воркер × 5 экзаменов = 20 минут
- **После оптимизации**: 3 воркера × 5 экзаменов = ~8 минут
- **Прирост**: **2.5x быстрее**

**Масштабирование воркеров:**

Для увеличения производительности можно добавить больше воркеров в `docker-compose.yml`:

```yaml
# Добавить в docker-compose.yml
queue-worker-4:
  # ... копия конфигурации queue-worker
```

Затем:
```bash
docker compose up -d --scale queue-worker=5  # Запустить 5 воркеров
```

### 10.2 Async AI Provider (экспериментально)

Для дополнительного ускорения можно включить асинхронные AI-запросы:

```env
AI_ASYNC_ENABLED=true
AI_PARALLEL_MAX=5  # Количество параллельных запросов к OpenAI
```

Это позволяет делать несколько AI-запросов внутри одной задачи параллельно:
- **Теоретический прирост**: до **2x** внутри одной задачи
- **Статус**: экспериментально, требует тестирования

**Внимание**: Async режим может увеличить нагрузку на OpenAI API и вызвать rate limiting.

### 10.3 Мониторинг производительности

```bash
# Время обработки задач
docker compose exec app php artisan tinker --execute="
\App\Models\GenerationTask::where('status', 'completed')
    ->selectRaw('type, AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_seconds')
    ->groupBy('type')
    ->get();
"

# Загрузка воркеров
docker stats --no-stream | grep queue-worker

# Количество обработанных задач
docker compose exec app php artisan tinker --execute="
echo 'Total tasks: ' . \App\Models\GenerationTask::count() . PHP_EOL;
echo 'Completed: ' . \App\Models\GenerationTask::where('status', 'completed')->count() . PHP_EOL;
echo 'Failed: ' . \App\Models\GenerationTask::where('status', 'failed')->count() . PHP_EOL;
"
```

---

## 11) Статус проекта и дальнейшее развитие

### Текущий статус

**✅ Stage 1 (Завершено):**
- Docker инфраструктура с 3 параллельными воркерами
- Nova админка с динамическими Cards и Actions
- AI Research Pipeline (5 стадий: Identity → Confidence → Overview → Sanity → Examples)
- Identity Verification с interactive clarification
- REST API для research и evaluation
- Document processing (PDF/DOCX + OCR)
- Rate Limiting для OpenAI API
- Activity Timeline и Heartbeat monitoring
- Comprehensive logging (GenerationLog)

**✅ Оптимизация производительности (Завершено):**
- 3 параллельных queue workers (2.5x прирост)
- Redis-based rate limiting
- Async AI Provider (готов к использованию)

**⏳ В разработке:**
1. `POST /api/evaluate/audio` — оценка аудио-ответов (ASR → текст → оценка речи)
2. `TokenUsage` модель — учёт токенов как отдельной сущности с агрегацией по user/exam/task
3. Exam similarity detection — предотвращение дублирования research
4. Knowledge reuse system — переиспользование результатов для похожих экзаменов

### Дальнейшие планы

**Stage 2 (Planned):**
- WebSocket для real-time обновлений статуса
- Laravel Horizon для визуального мониторинга очередей
- Prometheus + Grafana для метрик
- Circuit Breaker pattern для защиты от сбоев AI API

**Stage 3 (Future):**
- Laravel Octane + Swoole для настоящей async архитектуры
- Горизонтальное масштабирование (multi-server setup)
- Auto-scaling воркеров на основе load
- Advanced caching стратегии

---

## 12) Документация

### Основные документы

**Архитектура:**
- `docs/architecture/research-pipeline-architecture.md` — Детальная архитектура research пайплайна
- `docs/architecture/iterative_identity_verification.md` — Итеративная верификация идентичности
- `docs/architecture/NOVA_DYNAMIC_CARDS.md` — Динамические карточки в Nova
- `CLAUDE.md` — Инструкции для Claude Code (архитектура, паттерны, команды)

**Руководства:**
- `docs/guides/nova_ui_testing_guide.md` — Практическое руководство по тестированию через Nova UI
- `docs/guides/TESTING_ALL_SCENARIOS_ru.md` — Тестирование всех сценариев
- `docs/DOCUMENT_UPLOAD.md` — Загрузка документов (PDF/DOCX)
- `docs/QUEUE_OPTIMIZATION.md` — Оптимизация очередей и производительности

**Утилиты:**
- `docs/CONTEXT-PACK-README.md` — Генератор контекста проекта (`context-pack.sh`)
- `docs/resource-tools-guide.md` — Nova Resource Tools guide
- `docs/vue-integration.md` — Интеграция Vue.js компонентов

**Отчеты:**
- `docs/reports/final_exams_pipeline_success_report_28_10_2025.md` — Последний успешный запуск (3/3, 100%)

### Для разных ролей

**Разработчики:**
1. Начните с `CLAUDE.md` (полная архитектура)
2. Изучите `docs/architecture/research-pipeline-architecture.md`
3. Используйте `make ctx` для генерации контекста

**QA:**
1. `docs/guides/nova_ui_testing_guide.md` — UI тестирование
2. `docs/guides/TESTING_ALL_SCENARIOS_ru.md` — Все сценарии
3. `make test` — Запуск автотестов

**DevOps:**
1. Этот `README.md` — Команды для продакшна (раздел 6)
2. `docker-compose.yml` — Конфигурация контейнеров
3. `.env.example` — Переменные окружения
4. `docs/QUEUE_OPTIMIZATION.md` — Оптимизация производительности

**Продакт-менеджеры:**
1. `docs/requirements/requirements_updated.md` — Требования
2. `docs/requirements/RECOMMENDATIONS.md` — Рекомендации
3. `docs/reports/` — Отчеты о выполнении

---

## 13) Быстрые команды-шпаргалки

### Повседневная работа (Development)

```bash
# Запуск проекта
cd examHelp  # или cd /opt/examhelp/examHelp (продакшн)
make up

# После изменения кода
make fast-refresh

# Применение миграций
make migrate

# Запуск тестов
make test

# Проверка code style
make cs
make stan
```

### Продакшн (Production)

```bash
cd /opt/examhelp/examHelp

# Обновление из git
git pull origin master
make refresh

# Применение миграций (ВАЖНО: сделать бэкап БД!)
make migrate

# Мониторинг воркеров
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f

# Проверка здоровья
curl http://localhost:8080/health
docker compose ps

# Перезапуск при проблемах
docker compose restart queue-worker-1 queue-worker-2 queue-worker-3
make worker-restart
```

### Отладка (Debugging)

```bash
# Логи приложения
docker compose logs app -f --tail=100

# Логи воркеров
docker compose logs queue-worker-1 queue-worker-2 queue-worker-3 -f

# Очередь в Redis
docker compose exec redis redis-cli LLEN queues:default

# Tinker (REPL)
docker compose exec app php artisan tinker

# Failed jobs
docker compose exec app php artisan queue:failed

# Очистка кешей
make cache-clear
```

### Генерация контекста (Context Pack)

```bash
# Полный контекст проекта
make ctx

# Только модели и БД
make ctx-models-db

# Один файл со всеми зависимостями
make ctx-file FILE=app/Services/LanguageApp/ExamResearchService.php

# Результат в repo-context.md
```

---

## 14) Полезные ссылки

**Внутренние:**
- `CLAUDE.md` — Полная документация проекта для AI
- `docs/` — Вся документация
- `scripts/` — Вспомогательные скрипты
- `.env.example` — Пример конфигурации

**Внешние:**
- [Laravel Documentation](https://laravel.com/docs)
- [Laravel Nova Documentation](https://nova.laravel.com/docs)
- [OpenAI API Documentation](https://platform.openai.com/docs)
- [Redis Documentation](https://redis.io/docs)
- [Docker Compose Documentation](https://docs.docker.com/compose)

**Поддержка:**
- Issues/PR с описанием, ссылками на `docs/guides/TESTING_ALL_SCENARIOS_ru.md` и скринами/логами

---

**Последнее обновление:** 11 ноября 2025
**Версия:** 1.1 (Production-Ready)
**Статус:** ✅ Stage 1 Complete + Performance Optimization
**Текущий фокус:** Production deployment, monitoring, и подготовка Stage 2
