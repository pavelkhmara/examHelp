<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;

/**
 * Quick Check for Identity - проверка готовности полей для запуска исследования
 *
 * Responsibilities:
 * - Проверяет наличие минимально необходимых полей для Identity stage
 * - Возвращает список недостающих полей с приоритетами
 * - Определяет, готов ли экзамен к запуску исследования
 */
class QuickCheckService
{
    /**
     * Поля, необходимые для запуска Identity stage
     * Ключи - названия полей, значения - readable названия для UI
     */
    public const REQUIRED_FIELDS = [
        'title' => 'Exam Title',
        'language_of_test' => 'Language of Test',
        'level' => 'Level',
        'has_document_or_input' => 'Document or User Input',
        'exam_family' => 'Exam Family',
        'exam_provider' => 'Exam Provider',
        'description' => 'Exam Description',
    ];

    /**
     * Критичные поля (без них нельзя запускать)
     */
    public const CRITICAL_FIELDS = [
        'title',
    ];

    /**
     * Рекомендуемые поля (желательны, но не обязательны)
     */
    public const RECOMMENDED_FIELDS = [
        'language_of_test',
        'level',
        'has_document_or_input',
        'exam_family',
        'exam_provider',
        'description',
    ];

    /**
     * Проверить готовность экзамена к исследованию
     *
     * @return array
     *               [
     *               'ready' => bool,
     *               'checks' => ['field' => ['status' => 'ok|missing|empty', 'label' => '...']],
     *               'missing_critical' => ['field1', 'field2'],
     *               'missing_recommended' => ['field1', 'field2'],
     *               'completion_percentage' => 85,
     *               ]
     */
    public function check(Exam $exam): array
    {
        $checks = [];
        $missingCritical = [];
        $missingRecommended = [];
        $totalFields = count(self::REQUIRED_FIELDS);
        $completedFields = 0;

        foreach (self::REQUIRED_FIELDS as $field => $label) {
            $status = $this->checkField($exam, $field);
            $checks[$field] = [
                'status' => $status,
                'label' => $label,
                'critical' => in_array($field, self::CRITICAL_FIELDS),
                'recommended' => in_array($field, self::RECOMMENDED_FIELDS),
            ];

            if ($status === 'ok') {
                $completedFields++;
            } else {
                if (in_array($field, self::CRITICAL_FIELDS)) {
                    $missingCritical[] = $field;
                }
                if (in_array($field, self::RECOMMENDED_FIELDS)) {
                    $missingRecommended[] = $field;
                }
            }
        }

        $completionPercentage = round(($completedFields / $totalFields) * 100);
        $ready = empty($missingCritical);

        return [
            'ready' => $ready,
            'checks' => $checks,
            'missing_critical' => $missingCritical,
            'missing_recommended' => $missingRecommended,
            'completion_percentage' => $completionPercentage,
            'total_fields' => $totalFields,
            'completed_fields' => $completedFields,
        ];
    }

    /**
     * Проверить конкретное поле
     *
     * @return string 'ok' | 'missing' | 'empty'
     */
    protected function checkField(Exam $exam, string $field): string
    {
        switch ($field) {
            case 'title':
                return ! empty($exam->title) ? 'ok' : 'empty';

            case 'language_of_test':
                // Check in exam model field first, then identity, then user_meta
                $lang = $exam->language_of_test
                    ?? $exam->identity['exam_language'] // Note: in identity it's 'exam_language', not 'language_of_test'
                    ?? $exam->identity['canonical']['language_of_test']
                    ?? $exam->identity['language_of_test']
                    ?? $exam->user_meta['language_of_test']
                    ?? null;

                return ! empty($lang) ? 'ok' : 'missing';

            case 'level':
                return ! empty($exam->level) ? 'ok' : 'empty';

            case 'has_document_or_input':
                // Есть документ ИЛИ user_input
                $hasDocument = $exam->documents()->exists();
                $hasUserInput = ! empty($exam->user_input);

                return ($hasDocument || $hasUserInput) ? 'ok' : 'missing';

            case 'exam_family':
                // Check in identity (canonical first, then flat structure, then user_meta)
                $family = $exam->identity['canonical']['family']
                    ?? $exam->identity['exam_family']
                    ?? $exam->user_meta['exam_family']
                    ?? null;

                return ! empty($family) ? 'ok' : 'missing';

            case 'exam_provider':
                // Check in identity (canonical first, then flat structure, then user_meta)
                $provider = $exam->identity['canonical']['provider']
                    ?? $exam->identity['provider']
                    ?? $exam->user_meta['exam_provider']
                    ?? null;

                return ! empty($provider) ? 'ok' : 'missing';

            case 'description':
                return ! empty($exam->description) ? 'ok' : 'empty';

            default:
                return 'missing';
        }
    }

    /**
     * Получить список недостающих полей в человекочитаемом формате
     *
     * @param  array  $checkResult  Результат check()
     */
    public function getMissingFieldsMessage(array $checkResult): string
    {
        $messages = [];

        if (! empty($checkResult['missing_critical'])) {
            $criticalLabels = array_map(
                fn ($field) => self::REQUIRED_FIELDS[$field] ?? $field,
                $checkResult['missing_critical']
            );
            $messages[] = 'Critical fields: '.implode(', ', $criticalLabels);
        }

        if (! empty($checkResult['missing_recommended'])) {
            $recommendedLabels = array_map(
                fn ($field) => self::REQUIRED_FIELDS[$field] ?? $field,
                $checkResult['missing_recommended']
            );
            $messages[] = 'Recommended fields: '.implode(', ', $recommendedLabels);
        }

        return ! empty($messages) ? implode('. ', $messages) : 'All fields completed.';
    }

    /**
     * Получить HTML для отображения чек-листа в Nova
     *
     * @param  array  $checkResult  Результат check()
     */
    public function getChecklistHtml(array $checkResult): string
    {
        $html = '<div style="font-family: monospace; line-height: 1.6;">';
        $html .= sprintf(
            '<div style="margin-bottom: 10px;"><strong>Completion: %d%%</strong> (%d/%d fields)</div>',
            $checkResult['completion_percentage'],
            $checkResult['completed_fields'],
            $checkResult['total_fields']
        );

        foreach ($checkResult['checks'] as $field => $check) {
            $icon = $check['status'] === 'ok' ? '✔' : '✖';
            $color = $check['status'] === 'ok' ? 'green' : ($check['critical'] ? 'red' : 'orange');
            $badge = $check['critical'] ? ' <span style="color: red; font-size: 0.8em;">[CRITICAL]</span>' : '';

            $html .= sprintf(
                '<div style="color: %s;"><span style="font-weight: bold;">%s</span> %s%s</div>',
                $color,
                $icon,
                htmlspecialchars($check['label']),
                $badge
            );
        }

        $html .= '</div>';

        return $html;
    }
}
