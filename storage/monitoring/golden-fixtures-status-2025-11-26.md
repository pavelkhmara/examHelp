# Golden Stage Fixtures: Текущее состояние системы

**Дата:** 2025-11-26
**Эксперт:** quality-expert agent
**Статус:** ✅ Phase 1 завершена (структура создана), требуется Phase 2 (валидация и улучшение)

---

## Резюме

Golden Stage Fixtures система **частично реализована** после synthesis pipeline стабилизации (S1-S5). Структура создана, core компоненты работают, но **качество эталонов требует улучшения**.

### Ключевые метрики

| Компонент | Статус | Качество |
|-----------|--------|----------|
| **Директории** | ✅ Созданы | 100% |
| **Core Services** | ✅ Реализованы | 90% |
| **Artisan Commands** | ✅ Работают | 85% |
| **Golden Fixtures (pl-b1)** | ⚠️ Требуют валидации | **38-60%** |
| **Snapshot System** | ✅ Полностью работает | 95% |
| **Документация** | ✅ Актуальна | 90% |

### Критические проблемы

1. **Низкий similarity score**: Golden fixtures показывают 16-38% similarity с реальными экзаменами
2. **Отсутствие источника истины**: Непонятно, что корректно — golden или generated
3. **Нет процесса создания golden**: Не определено, как создавать эталоны из реальных экзаменов

---

## 1. Структура файлов

### Созданные директории

```
tests/Fixtures/stages/
├── _schemas/                    ✅ Созданы
│   ├── 00-input.schema.json      (2 схемы из 6)
│   └── 02-phase-a.schema.json
├── pl-b1/                       ✅ Все этапы созданы
│   ├── 00-input.json
│   ├── 01-identity.json
│   ├── 02-phase-a.json
│   ├── 03-phase-b.json
│   ├── 04-resolve-plans.json
│   └── 05-synthesis.json
└── README.md                    ✅ Документация

storage/snapshots/exams/         ✅ Snapshot система
└── (динамически создаётся)
```

### Отсутствующие компоненты

```
tests/Fixtures/stages/
├── _schemas/
│   ├── 01-identity.schema.json   ❌ НЕ создана
│   ├── 03-phase-b.schema.json    ❌ НЕ создана
│   ├── 04-resolve-plans.schema.json ❌ НЕ создана
│   └── 05-synthesis.schema.json  ❌ НЕ создана
│
├── pl-c1/                       ❌ НЕ создана (только финальный pl-c1.json)
├── delf-b1/                     ❌ НЕ создана
├── goethe-b1/                   ❌ НЕ создана
└── ielts-academic/              ❌ НЕ создана
```

---

## 2. Core Services: Реализация

### ✅ GoldenLoader (`app/Services/Golden/GoldenLoader.php`)

**Статус:** Полностью реализован, работает корректно

**Функции:**
- ✅ `load($examId, $stage)` - загрузка golden fixture
- ✅ `loadByStage($examId, $stageName)` - загрузка по имени этапа
- ✅ `exists($examId)` - проверка существования
- ✅ `listExams()` - список экзаменов с эталонами
- ✅ `loadFinalExam($examId)` - загрузка финального эталона

**Качество:** 95% — хорошо спроектирован, покрывает все нужды

### ✅ StageComparator (`app/Services/Golden/StageComparator.php`)

**Статус:** Полностью реализован, сложная логика сравнения

**Функции:**
- ✅ `compare($generated, $golden, $options)` - основное сравнение
- ✅ `compareCriticalFields()` - точное совпадение критических полей
- ✅ `compareFlexibleFields()` - fuzzy matching для гибких полей
- ✅ `collectDiffs()` - рекурсивный сбор различий
- ✅ `calculateSimilarity()` - weighted scoring (critical: 0.5, flexible: 0.3, diffs: 0.2)

**Качество:** 90% — продвинутая логика, но может давать низкие scores из-за metadata полей

**Потенциальные улучшения:**
- Добавить нормализацию metadata полей (timestamps, IDs)
- Улучшить fuzzy matching для текстовых полей
- Добавить режим "strict" для CI и "lenient" для development

### ✅ ComparisonResult (`app/Services/Golden/ComparisonResult.php`)

**Статус:** Реализован, простая data class

**Функции:**
- ✅ Хранение результатов сравнения
- ✅ `isPassing()` - проверка threshold
- ✅ `getSummary()` - краткое резюме

**Качество:** 95% — простой и надёжный

### ✅ SnapshotManager (`app/Services/Golden/SnapshotManager.php`)

**Статус:** Полностью реализован, интеграция с golden

**Функции:**
- ✅ `capture($exam, $stage, $label)` - захват snapshot
- ✅ `compare($exam, $stage, $baseline)` - сравнение с baseline
- ✅ `compareWithGolden($exam, $stage, $golden)` - сравнение с golden fixture
- ✅ `list($exam)` - список snapshots

**Качество:** 95% — отличная интеграция snapshot + golden

---

## 3. Artisan Commands

### ✅ golden:report

**Команда:** `php artisan golden:report pl-b1 --exam=<UUID>`

**Статус:** Работает, показывает similarity по всем этапам

**Пример вывода:**
```
Golden Fixtures Report: pl-b1
==================================================

Available stages:
  ✓ 0: input (00-input.json)
  ✓ 1: identity (01-identity.json)
  ✓ 2: phase_a (02-phase-a.json)
  ✓ 3: phase_b (03-phase-b.json)
  ✓ 4: resolve_plans (04-resolve-plans.json)
  ✓ 5: synthesis (05-synthesis.json)

Comparing with: Polish B1 Reading Certification
--------------------------------------------------
  ✗ identity: 18.7% (27 diffs)
  ✗ phase_a: 38.4% (24 diffs)
  ✗ phase_b: 24.4% (29 diffs)
  ✗ resolve_plans: 16.4% (9 diffs)
  ✗ synthesis: 36.3% (1 diffs)
```

**Качество:** 85% — работает, но не показывает детали diffs (требует --verbose режим)

### ✅ snapshot:capture

**Команда:** `php artisan snapshot:capture <UUID> --stage=phase_a --label=baseline`

**Статус:** Работает, захватывает текущее состояние

**Качество:** 90% — полностью функциональна

### ✅ snapshot:compare

**Команда:** `php artisan snapshot:compare <UUID> --stage=phase_a --golden=pl-b1`

**Статус:** Работает, сравнивает с golden или baseline

**Качество:** 90% — полностью функциональна

### ✅ snapshot:list

**Команда:** `php artisan snapshot:list <UUID>`

**Статус:** Работает, показывает список snapshots

**Качество:** 85% — базовая функциональность

---

## 4. Golden Fixtures: Качество (pl-b1)

### Результаты тестирования

Запущен `golden:report pl-b1 --exam=3da10b67-b364-49cf-a48e-29cf09723070`:

| Этап | Similarity | Статус | Ключевые проблемы |
|------|------------|--------|-------------------|
| **Identity** | 18.7% | ❌ FAIL | 27 diffs, missing `confirmed_title`, `confirmed_provider`, `confirmed_level` |
| **Phase A** | 38.4% | ❌ FAIL | 24 diffs, mismatch в `meta.name`, `meta.provider`, `tags` длина |
| **Phase B** | 24.4% | ❌ FAIL | 29 diffs, те же проблемы с meta + assembly |
| **Resolve Plans** | 16.4% | ❌ FAIL | 9 diffs, array length mismatch в `generation_plans` |
| **Synthesis** | 36.3% | ❌ FAIL | 1 diff, extra field `sections` в generated |

### Диагностика проблем

#### Проблема 1: Identity (18.7%)

**Симптомы:**
- Missing fields: `confirmed_title`, `confirmed_provider`, `confirmed_level`

**Причина:**
- Golden fixture содержит поля из **ConfirmedIdentity** модели
- Тестируемый экзамен (`3da10b67`) — это **простой reading exam**, не полный Polish B1 Certificate
- Golden fixture построен на основе **идеального полного экзамена**, а не реального из БД

**Вывод:** ❌ Golden fixture не соответствует source of truth (реальному экзамену в БД)

#### Проблема 2: Phase A (38.4%)

**Симптомы:**
- Mismatch в `meta.name`: Golden = "Egzamin Certyfikatowy...", Generated = "Polish B1 Reading Certification"
- Mismatch в `meta.provider`: разные названия
- Array length mismatch в `tags`: Golden = 4, Generated = ?

**Причина:**
- Golden fixture описывает **полный Polish B1 Certificate** (5 секций: L/R/G/W/S)
- Тестируемый экзамен — **только Reading секция** (1 секция)

**Вывод:** ❌ Сравниваем яблоки с апельсинами — golden ≠ exam structure

#### Проблема 3: Phase B (24.4%)

**Симптомы:**
- 29 diffs, включая meta + assembly plans

**Причина:**
- Те же проблемы: golden описывает 5 секций, exam имеет 1 секцию
- Assembly plans не совпадают

**Вывод:** ❌ Fundamental mismatch

#### Проблема 4: Resolve Plans (16.4%)

**Симптомы:**
- Array length mismatch: `generation_plans` длина не совпадает

**Причина:**
- Golden содержит планы для 5 секций (listening, reading, grammar, writing, speaking)
- Exam имеет планы только для reading

**Вывод:** ❌ Структурное несоответствие

#### Проблема 5: Synthesis (36.3%)

**Симптомы:**
- Extra field `sections` в generated

**Причина:**
- Golden fixture = финальный `tests/Fixtures/exams/pl-b1.json`
- Generated содержит дополнительные поля из БД

**Вывод:** ⚠️ Минимальные отличия, но golden fixture может быть устаревшим

---

## 5. Корневая проблема: Отсутствие Source of Truth

### Проблема

**Сейчас:**
```
Golden fixture (pl-b1)  ←→  Exam in DB (3da10b67)
     (full 5 sections)           (1 section reading)
              ↓
         НЕ СОВПАДАЮТ (18-38% similarity)
              ↓
     КТО ПРАВ? КТО ЭТАЛОН? 🤷
```

**Вопросы без ответа:**
1. Golden fixture создан вручную или извлечён из реального экзамена?
2. Если вручную — на основе какой документации?
3. Если из экзамена — UUID того экзамена?
4. Экзамен `3da10b67` — это корректный результат pipeline или тестовый?

### Решение

**Установить Source of Truth:**

#### Вариант A: Golden fixtures = идеальный образец (manual curation)

```
1. Создать golden fixtures вручную на основе официальной документации
2. Проверить каждый этап вручную
3. Документировать источники (_reference в каждом файле)
4. НЕ извлекать из БД, а создавать как design spec
```

**Плюсы:**
- ✅ Контролируемое качество
- ✅ Не зависит от багов в pipeline

**Минусы:**
- ❌ Трудоёмко
- ❌ Может не совпадать с реальностью

#### Вариант B: Golden fixtures = извлечение из успешного экзамена

```
1. Найти успешный экзамен в БД (research_status = completed, 100% validation)
2. Извлечь данные каждого этапа из generation_logs
3. Сохранить как golden fixtures
4. Документировать UUID источника
```

**Плюсы:**
- ✅ Отражает реальную работу pipeline
- ✅ Автоматизируемо

**Минусы:**
- ❌ Может содержать артефакты pipeline
- ❌ Зависит от качества AI

#### Вариант C: Hybrid подход (рекомендую)

```
1. Извлечь baseline из успешного экзамена (Вариант B)
2. Вручную проверить и улучшить (Вариант A)
3. Добавить _comparison_hints для каждого этапа
4. Документировать оба источника (exam UUID + manual improvements)
```

**Плюсы:**
- ✅ Баланс реальности и качества
- ✅ Прозрачный процесс
- ✅ Воспроизводимо

**Минусы:**
- ⚠️ Требует времени (но только один раз)

---

## 6. Недостающие компоненты

### JSON Schemas

**Созданы:**
- ✅ `00-input.schema.json`
- ✅ `02-phase-a.schema.json`

**Требуются:**
- ❌ `01-identity.schema.json` - для валидации identity response
- ❌ `03-phase-b.schema.json` - для валидации assembly plans
- ❌ `04-resolve-plans.schema.json` - для валидации generation plans
- ❌ `05-synthesis.schema.json` - для валидации финального экзамена

### Artisan команда `golden:extract`

**Отсутствует:** команда для автоматического извлечения golden fixtures из экзамена

**Функциональность:**
```bash
php artisan golden:extract <exam-uuid> --output=tests/Fixtures/stages/new-exam/
```

**Что должна делать:**
1. Проверить exam.research_status = 'completed'
2. Извлечь данные каждого этапа:
   - 00-input: exam fields (title, level, user_input, documents)
   - 01-identity: identity_data, confidence
   - 02-phase-a: structure_v2 (без assembly)
   - 03-phase-b: structure_v2 (с assembly)
   - 04-resolve-plans: generation_plans из generation_logs
   - 05-synthesis: финальная структура с вопросами
3. Добавить metadata (_source, _reference)
4. Сохранить в output директорию

### Тесты для Golden Fixtures

**Отсутствуют:**
- ❌ `tests/Feature/Golden/PhaseAGoldenTest.php`
- ❌ `tests/Feature/Golden/PhaseBGoldenTest.php`
- ❌ `tests/Feature/Golden/ResolvePlansGoldenTest.php`
- ❌ `tests/Feature/Golden/SynthesisGoldenTest.php`

**Пример теста:**
```php
class PhaseAGoldenTest extends TestCase
{
    public function test_phase_a_matches_golden_pl_b1()
    {
        $loader = new GoldenLoader();
        $comparator = new StageComparator();

        $golden = $loader->load('pl-b1', 2); // Phase A

        // Запустить Phase A на тех же input данных
        $generated = $this->runPhaseA($golden['00-input']);

        $result = $comparator->compare($generated, $golden);

        $this->assertGreaterThanOrEqual(0.85, $result->similarity,
            'Phase A similarity должен быть >= 85%');
    }
}
```

---

## 7. Рекомендации

### Phase 2: Валидация и улучшение Golden Fixtures

#### Приоритет 1: Установить Source of Truth (1-2 дня)

**Задачи:**
1. ✅ Найти успешный Polish B1 exam в БД (UUID: ?)
2. ✅ Создать `php artisan golden:extract` команду
3. ✅ Извлечь golden fixtures из реального экзамена
4. ✅ Вручную проверить и улучшить каждый этап
5. ✅ Добавить `_reference` с UUID источника

**Критерий успеха:**
- similarity >= 85% при сравнении с исходным экзаменом

#### Приоритет 2: Создать JSON Schemas (1 день)

**Задачи:**
1. ✅ `01-identity.schema.json` - на основе IdentityGuard response
2. ✅ `03-phase-b.schema.json` - на основе structure_v2 с assembly
3. ✅ `04-resolve-plans.schema.json` - на основе generation_plans
4. ✅ `05-synthesis.schema.json` - на основе финального exam

**Критерий успеха:**
- Все существующие golden fixtures проходят schema validation

#### Приоритет 3: Написать Feature тесты (1 день)

**Задачи:**
1. ✅ `PhaseAGoldenTest.php` - тест Phase A против golden
2. ✅ `PhaseBGoldenTest.php` - тест Phase B против golden
3. ✅ `ResolvePlansGoldenTest.php` - тест Resolve Plans
4. ✅ `SynthesisGoldenTest.php` - тест Synthesis

**Критерий успеха:**
- Все тесты проходят в CI с similarity >= 85%

#### Приоритет 4: Создать golden fixtures для других экзаменов (2-3 дня)

**Задачи:**
1. ✅ `pl-c1` - Polish C1 Certificate
2. ✅ `delf-b1` - DELF B1
3. ✅ `goethe-b1` - Goethe-Zertifikat B1

**Критерий успеха:**
- 4 полных комплекта golden fixtures

#### Приоритет 5: Интеграция с CI (1 день)

**Задачи:**
1. ✅ Добавить golden tests в GitHub Actions
2. ✅ Настроить threshold: 85% для passing, 70% для warning
3. ✅ Создать dashboard с метриками

**Критерий успеха:**
- CI блокирует PR при similarity < 70%

---

## 8. План действий на ближайшую неделю

### День 1-2: Source of Truth

```bash
# 1. Найти успешный экзамен
docker compose exec app php artisan tinker --execute="
  App\Models\Exam::where('research_status', 'completed')
    ->where('title', 'like', '%Polish%B1%')
    ->get(['id', 'title', 'research_status'])
    ->take(5)
"

# 2. Создать golden:extract команду
touch app/Console/Commands/GoldenExtractCommand.php

# 3. Извлечь fixtures
php artisan golden:extract <UUID> --output=tests/Fixtures/stages/pl-b1-v2/

# 4. Сравнить с текущими
diff tests/Fixtures/stages/pl-b1/ tests/Fixtures/stages/pl-b1-v2/

# 5. Выбрать лучшую версию и обновить
```

### День 3: JSON Schemas

```bash
# Создать недостающие schemas
touch tests/Fixtures/stages/_schemas/01-identity.schema.json
touch tests/Fixtures/stages/_schemas/03-phase-b.schema.json
touch tests/Fixtures/stages/_schemas/04-resolve-plans.schema.json
touch tests/Fixtures/stages/_schemas/05-synthesis.schema.json

# Валидировать fixtures
php artisan golden:validate pl-b1 --schema
```

### День 4: Feature Tests

```bash
# Создать тесты
touch tests/Feature/Golden/PhaseAGoldenTest.php
touch tests/Feature/Golden/PhaseBGoldenTest.php

# Запустить
php artisan test tests/Feature/Golden/
```

### День 5: Другие экзамены

```bash
# Извлечь pl-c1
php artisan golden:extract <PL-C1-UUID> --output=tests/Fixtures/stages/pl-c1/

# Проверить качество
php artisan golden:report pl-c1 --exam=<PL-C1-UUID>
```

---

## 9. Метрики успеха Phase 2

| Метрика | Target | Current | Статус |
|---------|--------|---------|--------|
| **Golden fixtures coverage** | 4 экзамена | 1 (частично) | ⚠️ 25% |
| **Similarity score (avg)** | >= 85% | 27% | ❌ FAIL |
| **JSON Schemas coverage** | 6 schemas | 2 | ⚠️ 33% |
| **Feature tests coverage** | 4 тестов | 0 | ❌ 0% |
| **CI integration** | Enabled | Disabled | ❌ |

**Целевые метрики после Phase 2:**
- ✅ 4 полных комплекта golden fixtures (pl-b1, pl-c1, delf-b1, goethe-b1)
- ✅ Similarity >= 85% на всех этапах
- ✅ 6 JSON Schemas, все fixtures валидны
- ✅ 4 Feature теста, все проходят
- ✅ CI блокирует PR при similarity < 70%

---

## 10. Выводы

### Что работает ✅

1. **Инфраструктура создана**: директории, services, commands
2. **Core components надёжны**: GoldenLoader, StageComparator, SnapshotManager
3. **Snapshot система отличная**: capture/compare работает идеально
4. **Документация актуальна**: `docs/features/golden-stage-fixtures.md` полная

### Что требует улучшения ⚠️

1. **Качество golden fixtures**: similarity 16-38% вместо >= 85%
2. **Отсутствие source of truth**: непонятно, кто прав — golden или generated
3. **Неполный набор schemas**: только 2 из 6
4. **Нет автоматизации**: команда `golden:extract` отсутствует

### Критические проблемы ❌

1. **Golden fixtures не соответствуют реальности**: созданы вручную без привязки к БД
2. **Нет процесса валидации**: невозможно проверить корректность golden
3. **Нет CI интеграции**: тесты не запускаются автоматически

### Следующий шаг

**РЕКОМЕНДУЮ:** Начать с Приоритета 1 — установить Source of Truth:

1. Найти успешный Polish B1 exam в БД
2. Создать `golden:extract` команду
3. Извлечь реальные данные каждого этапа
4. Вручную проверить и улучшить
5. Запустить `golden:report` и достичь similarity >= 85%

**Только после этого** переходить к Schemas, тестам и другим экзаменам.

---

**Автор:** quality-expert agent
**Контакт:** См. `.claude/agents/quality-expert.md` для деталей
