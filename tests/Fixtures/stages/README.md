# Golden Stage Fixtures

Эталонные данные для каждого этапа генерации экзамена.

## Структура

```
stages/
├── _schemas/           # JSON Schemas для валидации эталонов
├── pl-b1/              # Эталоны для Polish B1 Certificate
│   ├── 00-input.json          # Входные данные
│   ├── 01-identity.json       # После Identity stage
│   ├── 02-phase-a.json        # После Phase A (skeleton)
│   ├── 03-phase-b.json        # После Phase B (assembly plans)
│   ├── 04-resolve-plans.json  # После Resolve Plans
│   └── 05-synthesis.json      # Ссылка на финальный эталон
├── pl-c1/              # Эталоны для Polish C1 Certificate
└── ...
```

## Этапы Pipeline

| # | Этап | Описание | Что генерируется |
|---|------|----------|------------------|
| 0 | Input | Входные данные | title, level, documents |
| 1 | Identity | Верификация экзамена | identity_data, confidence |
| 2 | Phase A | Генерация skeleton | sections[] без assembly |
| 3 | Phase B | Assembly планы | sections[].assembly |
| 4 | Resolve Plans | Планы генерации | generation_plans[] |
| 5 | Synthesis | Финальный контент | questions с текстами |

## Использование

### Загрузка эталона

```php
$loader = new GoldenLoader();
$golden = $loader->load('pl-b1', 2); // Phase A эталон
```

### Сравнение с эталоном

```php
$comparator = new StageComparator();
$result = $comparator->compare($generated, $golden);

echo $result->getSummary(); // "✓ PASSED (similarity: 92.5%)"
```

### Artisan команда

```bash
# Показать отчёт по эталонам
php artisan golden:report pl-b1

# Сравнить текущий экзамен с эталоном
php artisan golden:compare pl-b1 --stage=2
```

## Формат файлов

Каждый файл эталона содержит:

```json
{
  "$schema": "../_schemas/XX-stage.schema.json",
  "version": "1.0",
  "stage": "stage_name",
  "exam_id": "pl-b1",

  // Данные этапа...

  "_comparison_hints": {
    "critical_fields": ["поля, которые ДОЛЖНЫ совпадать"],
    "flexible_fields": ["поля с допустимыми вариациями"],
    "ignore_fields": ["поля, которые игнорируются"]
  },

  "_notes": "Комментарии для разработчиков"
}
```

## Создание новых эталонов

1. Запустите pipeline на реальном экзамене
2. Вручную проверьте результат каждого этапа
3. Сохраните проверенные данные как эталон:

```bash
php artisan golden:extract <exam-uuid> --output=tests/Fixtures/stages/new-exam/
```

4. Добавьте `_comparison_hints` для настройки сравнения

## Документация

Подробная документация: `docs/features/golden-stage-fixtures.md`
