<?php

namespace App\Services\LanguageApp;

use InvalidArgumentException;

/**
 * Централизованная генерация и валидация question_id.
 *
 * Обеспечивает единую точку генерации ID для вопросов, устраняя дублирование логики
 * между StructureMaterializer и QuestionAttacher.
 *
 * Форматы ID:
 * - Grouped questions: {group_id}_{question_id} (например, "listening-task-1_list-q1")
 * - Ungrouped questions: {section_key}_{question_id} (например, "reading_q1", "sec-reading_q1")
 *
 * @see https://github.com/pavelkhmara/examHelp/blob/main/docs/architecture/synthesis-pipeline-rollout-plan.md Phase 2.1
 */
class QuestionIdGenerator
{
    /**
     * Формат ID разделитель.
     */
    private const SEPARATOR = '_';

    /**
     * Regex для валидации ID компонентов.
     * Разрешены: буквы, цифры, дефисы.
     */
    private const COMPONENT_PATTERN = '/^[a-zA-Z0-9\-]+$/';

    /**
     * Генерирует question_id для вопроса внутри группы.
     *
     * @param  string  $groupId  ID группы вопросов (например, "listening-task-1")
     * @param  string  $questionId  Raw ID вопроса (например, "list-q1")
     * @return string Уникальный question_id (например, "listening-task-1_list-q1")
     *
     * @throws InvalidArgumentException Если groupId или questionId невалидны
     */
    public static function forGroupedQuestion(string $groupId, string $questionId): string
    {
        self::validateComponent($groupId, 'group_id');
        self::validateComponent($questionId, 'question_id');

        return $groupId.self::SEPARATOR.$questionId;
    }

    /**
     * Генерирует question_id для ungrouped вопроса.
     *
     * @param  string  $sectionKey  Ключ секции (например, "reading", "sec-listening")
     * @param  string  $questionId  Raw ID вопроса (например, "q1")
     * @return string Уникальный question_id (например, "reading_q1")
     *
     * @throws InvalidArgumentException Если sectionKey или questionId невалидны
     */
    public static function forUngroupedQuestion(string $sectionKey, string $questionId): string
    {
        self::validateComponent($sectionKey, 'section_key');
        self::validateComponent($questionId, 'question_id');

        return $sectionKey.self::SEPARATOR.$questionId;
    }

    /**
     * Проверяет, валиден ли формат question_id.
     *
     * Валидный ID должен содержать минимум 2 компонента, разделённых подчёркиванием,
     * и каждый компонент должен соответствовать разрешённому паттерну.
     *
     * @param  string  $questionId  Question ID для валидации
     * @return bool True если ID валиден, иначе false
     */
    public static function isValid(string $questionId): bool
    {
        if (empty($questionId)) {
            return false;
        }

        $parts = explode(self::SEPARATOR, $questionId);

        // Минимум 2 компонента (prefix_id)
        if (count($parts) < 2) {
            return false;
        }

        // Все компоненты должны соответствовать паттерну
        foreach ($parts as $part) {
            if (! preg_match(self::COMPONENT_PATTERN, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Разбирает question_id на компоненты.
     *
     * Возвращает массив с ключами:
     * - 'prefix': Первый компонент (group_id или section_key)
     * - 'question_id': Остальные компоненты объединённые (raw question_id)
     * - 'is_grouped': true если формат соответствует grouped question
     *
     * @param  string  $questionId  Question ID для разбора
     * @return array{prefix: string, question_id: string, is_grouped: bool}
     *
     * @throws InvalidArgumentException Если question_id невалиден
     */
    public static function parse(string $questionId): array
    {
        if (! self::isValid($questionId)) {
            throw new InvalidArgumentException("Invalid question_id format: {$questionId}");
        }

        $parts = explode(self::SEPARATOR, $questionId);
        $prefix = array_shift($parts);
        $rawQuestionId = implode(self::SEPARATOR, $parts);

        // Heuristic: grouped questions обычно имеют более сложный prefix
        // (например, "listening-task-1"), ungrouped - простой ("reading", "sec-listening")
        $isGrouped = str_contains($prefix, '-');

        return [
            'prefix' => $prefix,
            'question_id' => $rawQuestionId,
            'is_grouped' => $isGrouped,
        ];
    }

    /**
     * Валидирует компонент ID.
     *
     * @param  string  $component  Компонент для валидации
     * @param  string  $name  Имя компонента (для ошибки)
     *
     * @throws InvalidArgumentException Если компонент невалиден
     */
    private static function validateComponent(string $component, string $name): void
    {
        if (empty($component)) {
            throw new InvalidArgumentException("{$name} cannot be empty");
        }

        if (! preg_match(self::COMPONENT_PATTERN, $component)) {
            throw new InvalidArgumentException(
                "{$name} must contain only letters, numbers, and hyphens. Got: {$component}"
            );
        }
    }
}
