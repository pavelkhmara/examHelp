# Длительный мониторинг: Baseline метрики

**Дата начала**: 2025-11-27 07:11:35
**Тип**: Long-term Monitoring (3-5 days)
**Провайдер**: OpenAI GPT-5-mini (production)

---

## Baseline метрики (до создания тестовых экзаменов)

### Системный статус

| Метрика | Значение | Статус |
|---------|----------|--------|
| AI Provider | OpenAI | ✅ |
| AI Model | gpt-5-mini | ✅ |
| Workers | 10 | ✅ |
| Rate Limit | 60 RPM | ✅ |

### Метрики pipeline (только новые экзамены >= 2025-11-27)

| Метрика | Baseline | Target | Статус |
|---------|----------|--------|--------|
| **New Exams** | 0 | 2-5 | ⏳ Pending |
| **Synthesis Tasks (24h)** | 4 | N/A | ✅ |
| **Success Rate** | 100% (4/4) | ≥ 95% | ✅ PASS |
| **ID Mismatches** | 0 | 0 | ✅ PASS |
| **Duplicates** | 0 | 0 | ✅ PASS |
| **Empty Skeletons** | 0 | 0 | ✅ PASS |

---

## План мониторинга

### Создание тестовых экзаменов

**Цель**: Создать 2 экзамена через Nova UI с полным Research → Synthesis flow

**Экзамен 1: Minimal IELTS Listening**
- Title: `[LONG-MONITOR-1] IELTS Listening Minimal`
- Sections: 1 (Listening)
- Groups: 1
- Questions: 3-5
- User input: "IELTS Listening test with 1 section, 1 audio recording, 5 multiple choice questions about daily conversation"

**Экзамен 2: Standard TOEFL Reading**
- Title: `[LONG-MONITOR-2] TOEFL Reading Standard`
- Sections: 1 (Reading)
- Groups: 2
- Questions: 8-10
- User input: "TOEFL Reading test with 1 section, 2 text passages about academic topics, 10 questions total (mix of multiple choice and true/false)"

### Точки проверки

**Ежедневные проверки** (3-5 дней):
1. Запуск `scripts/monitoring_new_exams.php`
2. Фиксация метрик в отдельный файл
3. Проверка логов при появлении ошибок

**Критерии успеха**:
- Success Rate ≥ 95% на протяжении всего периода
- Zero ID mismatch incidents
- Zero duplicate questions
- Zero empty skeletons в completed экзаменах

---

## Инструменты мониторинга

### Скрипты

1. **`scripts/monitoring_new_exams.php`**
   - Проверка метрик только для новых экзаменов (created >= 2025-11-27)
   - Фильтрует legacy данные (568 старых ID mismatches)
   - Фокус на качестве нового pipeline

2. **`scripts/daily_monitoring_check.php`**
   - Общий мониторинг всех экзаменов
   - Используется для справки

### Логирование

- **Checkpoint logs**: `[Contract:*]` в Laravel logs
- **Generation logs**: `generation_logs` table
- **Failed jobs**: `failed_jobs` table

---

## Связанные документы

- `docs/architecture/synthesis-pipeline-rollout-plan.md` - План rollout
- `docs/architecture/synthesis-pipeline-contracts.md` - Контракты
- `docs/guides/synthesis-pipeline-monitoring-guide.md` - Гайд по мониторингу
- `storage/monitoring/accelerated-monitoring-runs-2025-11-26.md` - Ускоренный мониторинг

---

**Статус**: ✅ Baseline зафиксирован, готово к созданию тестовых экзаменов

**Автор**: Claude Code
**Дата**: 2025-11-27 07:11
**Версия**: 1.0
