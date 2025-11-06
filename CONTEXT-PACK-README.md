# 📚 context-pack.sh - Enhanced Laravel Context Generator

Улучшенный скрипт для генерации полного контекста Laravel приложения в единый markdown-файл.

## 🎯 Что нового в улучшенной версии

### Добавленные секции:

1. **📋 Table of Contents** - автоматически генерируемое оглавление с якорями
2. **📦 Project Metadata** - версии Laravel/PHP, ключевые зависимости из composer.json и package.json
3. **🏗️ Architecture Overview** - детальная статистика компонентов и используемый стек технологий
4. **🛡️ Middleware** - список и содержимое middleware с конфигурацией
5. **🔐 Policies** - политики авторизации
6. **⚡ Jobs & Queues** - задачи для очередей с конфигурацией
7. **📢 Events & Listeners** - события и слушатели с регистрацией
8. **🖥️ Console Commands** - кастомные artisan-команды
9. **🔄 API Resources** - API-ресурсы для трансформации данных
10. **🔄 Migrations** - список всех миграций + последние 10 в деталях
11. **🛠️ Service Providers** - провайдеры и bootstrap-конфигурация
12. **👑 Enhanced Nova Resources** - Nova Resources + Actions + Filters + Lenses + Metrics

### Улучшенные секции:

- **Models** - добавлен граф связей (relationship map), улучшенное отображение
- **Database Schema** - более подробные таблицы с индексами и FK
- **Controllers** - показывается список методов перед полным кодом
- **Routes** - добавлен вывод `artisan route:list`
- **Tests** - показываются имена тестовых методов
- **Frontend** - расширена поддержка TypeScript, Vue, JSX/TSX файлов

## 🚀 Использование

### Базовый запуск (полный контекст)

```bash
./context-pack.sh
# или
./context-pack.sh all
```

Генерирует файл `repo-context.md` со всеми секциями.

### Режимы работы

#### 1. Метаданные и архитектура
```bash
./context-pack.sh metadata
```
Генерирует только информацию о проекте, версиях, зависимостях и статистику.

#### 2. Только модели
```bash
./context-pack.sh models
```
Генерирует подробную информацию о моделях с relationships.

#### 3. Только база данных
```bash
./context-pack.sh db
```
Генерирует живую схему базы данных из INFORMATION_SCHEMA.

#### 4. Модели + База данных
```bash
./context-pack.sh models+db
```
Комбинирует информацию о моделях и схеме БД.

#### 5. Nova ресурсы
```bash
./context-pack.sh nova
```
Генерирует информацию о Nova Resources, Actions, Filters, Lenses, Metrics.

#### 6. Конкретный файл (File Insight)
```bash
./context-pack.sh file app/Services/MyService.php
./context-pack.sh file "App\Services\MyService"
./context-pack.sh file MyService.php
```

Генерирует детальную информацию о конкретном файле:
- Полный код файла
- Все импорты и зависимости
- Связанные файлы (автоматически резолвит PSR-4 пути)
- Используемые views
- Метрики кода (количество классов, методов, свойств)
- Список публичных методов

### Кастомизация вывода

#### Изменить имя выходного файла
```bash
OUT=custom-output.md ./context-pack.sh
OUT=docs/full-context.md ./context-pack.sh all
```

#### Режим отладки
```bash
DEBUG=1 ./context-pack.sh
```
Выводит детальную отладочную информацию в stderr.

#### Кастомная команда artisan
```bash
# Если используется не Docker
ARTISAN_CMD="php artisan" ./context-pack.sh

# Для кастомного Docker setup
ARTISAN_CMD="docker exec my-app php artisan" ./context-pack.sh
```

## 📋 Структура сгенерированного контекста

```markdown
1. Project Metadata
   - Environment info (git branch, PHP version)
   - Laravel & key dependencies
   - Frontend dependencies
   - Available artisan commands

2. Architecture Overview
   - Component statistics (counts)
   - Technology stack detected

3. Complete File Structure
   - Tree view of project

4. Core Configuration Files
   - composer.json, package.json, vite, tailwind, etc.
   - Laravel config files

5. Database Schema (live)
   - All tables with columns, types, keys
   - Foreign keys
   - ER Summary

6. Models & Relationships
   - Summary table
   - Detailed model info
   - Relationship graph

7. Routes
   - Web routes
   - API routes
   - Console routes
   - Full route list

8. Controllers
   - List of methods
   - Full code

9. Services
   - Service methods
   - Full code

10. Middleware
    - List
    - Details
    - Registration config

11. Policies
    - List
    - Policy methods

12. Jobs & Queues
    - Job list
    - Job details
    - Queue configuration

13. Events & Listeners
    - Events
    - Listeners
    - Registration

14. Console Commands
    - Custom command list
    - Command signatures
    - Full code

15. API Resources
    - Resource list
    - Resource details

16. Migrations
    - Chronological list
    - Recent 10 migrations
    - Migration status

17. Service Providers
    - Provider list
    - Provider details
    - Bootstrap config

18. Nova Resources
    - Resources
    - Actions
    - Filters
    - Lenses
    - Dashboards & Metrics

19. Frontend & Views
    - Blade views
    - JavaScript/TypeScript files
    - CSS/SCSS files

20. Tests
    - Test files
    - Test methods
    - Test configuration

21. Project Summary
    - Repository statistics
    - Architecture summary
    - Security notes
    - Generation info
```

## 🔧 Требования

- Bash 4.0+
- PHP 7.4+ (для Laravel bootstrap)
- Git (опционально, для улучшенного поиска файлов)
- Docker Compose (опционально, если используется контейнеризация)
- tree (опционально, для красивого дерева файлов)

## 📊 Примеры использования

### Пример 1: Подготовка контекста для AI-ассистента

```bash
# Генерируем полный контекст
./context-pack.sh all

# Контекст сохраняется в repo-context.md
# Теперь можно загрузить его в ChatGPT/Claude для анализа проекта
```

### Пример 2: Быстрая проверка схемы БД

```bash
./context-pack.sh db
cat repo-context.md | grep -A 50 "Database Tables"
```

### Пример 3: Анализ конкретного сервиса со всеми зависимостями

```bash
./context-pack.sh file app/Services/LanguageApp/ExamResearchService.php
```

Скрипт автоматически найдет и включит:
- Полный код сервиса
- Все импортированные классы
- Связанные модели
- Используемые view
- Метрики кода

### Пример 4: Документация для нового разработчика

```bash
# Генерируем контекст в папку docs
OUT=docs/project-overview.md ./context-pack.sh all

# Теперь docs/project-overview.md содержит полную документацию проекта
```

### Пример 5: Только информация о архитектуре

```bash
./context-pack.sh metadata
```

Быстро получаем:
- Версии всех ключевых зависимостей
- Статистику компонентов
- Список используемых технологий

## 🎨 Особенности

### Автоматическое определение окружения

Скрипт автоматически определяет:
- Docker Compose (использует `docker compose exec -T app php artisan`)
- Локальное окружение (использует `php artisan`)

### Умный резолвинг файлов

В режиме `file` скрипт понимает 3 формата:
1. Прямой путь: `app/Services/MyService.php`
2. PSR-4 класс: `App\Services\MyService`
3. Basename: `MyService.php` (ищет по всему проекту)

### Безопасность

- Никогда не показывает значения из `.env`
- Показывает только структуру `.env.example`
- Предупреждает о необходимости проверки перед шарингом

## 🐛 Отладка

Если что-то не работает:

```bash
# Включаем DEBUG режим
DEBUG=1 ./context-pack.sh metadata

# Проверяем, что Laravel может быть загружен
php artisan list

# Проверяем подключение к БД
php artisan migrate:status

# Для Docker
docker compose exec app php artisan list
```

## 💡 Советы

1. **Для больших проектов** начните с `metadata` режима, затем генерируйте нужные секции отдельно
2. **Для AI-анализа** используйте режим `all`, но будьте готовы к большому файлу (может быть 10k+ строк)
3. **Для code review** используйте режим `file` для анализа конкретного файла со всеми зависимостями
4. **Для документации** режим `all` идеален для onboarding новых разработчиков

## 📝 Примеры вывода

### Секция Models Summary

```markdown
| Model | Table | Relationships |
|-------|-------|---------------|
| User | `users` | 5 |
| Exam | `exams` | 3 |
| Question | `questions` | 2 |
```

### Секция Architecture Overview

```markdown
| Component | Count |
|-----------|-------|
| Controllers | 15 |
| Models | 12 |
| Services | 8 |
| Middleware | 6 |
| Jobs | 4 |
| Tests | 32 |
```

## 🎯 Когда использовать каждый режим

| Режим | Когда использовать | Время генерации |
|-------|-------------------|-----------------|
| `metadata` | Быстрый overview проекта | ~2-5 сек |
| `models` | Анализ структуры данных | ~5-10 сек |
| `db` | Проверка схемы БД | ~10-30 сек |
| `models+db` | Полный анализ data layer | ~15-40 сек |
| `nova` | Анализ админ-панели | ~5-10 сек |
| `file` | Глубокий анализ файла | ~2-5 сек |
| `all` | Полная документация | ~30-120 сек |

## 🔄 Обновления в этой версии

✅ Добавлен Table of Contents с навигацией
✅ Расширена секция метаданных (версии, зависимости)
✅ Добавлены секции: Middleware, Policies, Commands, Jobs, Events
✅ Улучшен парсинг моделей с relationship graph
✅ Добавлены миграции и Service Providers
✅ Расширена секция Nova (Actions, Filters, Lenses)
✅ Добавлены API Resources
✅ Улучшено форматирование и структура
✅ Добавлена статистика по архитектуре
✅ Улучшен режим file insight

---

**Автор улучшений:** Claude AI
**Дата:** 2025-11-05
**Версия:** 2.0
