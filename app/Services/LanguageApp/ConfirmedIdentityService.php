<?php

namespace App\Services\LanguageApp;

use App\Models\ConfirmedIdentity;
use App\Models\Exam;
use App\Models\GenerationTask;
use Illuminate\Support\Facades\Log;

/**
 * ConfirmedIdentity Service - работа с подтвержденной идентичностью экзамена
 *
 * Responsibilities:
 * - Создание подтвержденной идентичности после успешного Identity stage
 * - Проверка, нужно ли повторно запускать Identity
 * - Инвалидация при изменении влияющих полей
 * - Отслеживание истории подтверждений
 */
class ConfirmedIdentityService
{
    /**
     * Поля экзамена, которые влияют на идентичность
     * При изменении этих полей нужно перезапускать Identity
     */
    public const IDENTITY_AFFECTING_FIELDS = [
        'title',
        'user_input',
        'level',
        'description',
        // Также: изменение документов (отслеживается отдельно)
    ];

    /**
     * Создать подтвержденную идентичность
     *
     * @param  array  $identityData  Данные идентичности из Identity stage
     * @param  GenerationTask  $task  Задача, которая подтвердила
     */
    public function createConfirmedIdentity(
        Exam $exam,
        array $identityData,
        GenerationTask $task
    ): ConfirmedIdentity {
        // Инвалидировать предыдущие подтверждения
        $exam->confirmedIdentities()
            ->where('is_valid', true)
            ->update(['is_valid' => false]);

        // Создать новое подтверждение
        $sourceFields = $this->extractSourceFields($exam);

        $confirmed = ConfirmedIdentity::create([
            'exam_id' => $exam->id,
            'identity_data' => $identityData,
            'source_fields' => $sourceFields,
            'confirmed_at' => now(),
            'confirmed_by_task_id' => $task->id,
            'is_valid' => true,
        ]);

        Log::info('ConfirmedIdentity created', [
            'exam_id' => $exam->id,
            'confirmed_identity_id' => $confirmed->id,
            'task_id' => $task->id,
        ]);

        return $confirmed;
    }

    /**
     * Проверить, нужно ли перезапускать Identity stage
     *
     * @return array ['should_rerun' => bool, 'reason' => string|null, 'confirmed_identity' => ConfirmedIdentity|null]
     */
    public function shouldRerunIdentity(Exam $exam): array
    {
        // Проверяем наличие действующего подтверждения
        $confirmedIdentity = $exam->confirmedIdentity()
            ->where('is_valid', true)
            ->first();

        if (! $confirmedIdentity) {
            return [
                'should_rerun' => true,
                'reason' => 'No confirmed identity exists',
                'confirmed_identity' => null,
            ];
        }

        // Проверяем, изменились ли влияющие поля
        $currentFields = $this->extractSourceFields($exam);
        if ($confirmedIdentity->hasSourceFieldsChanged($currentFields)) {
            return [
                'should_rerun' => true,
                'reason' => 'Identity-affecting fields have changed',
                'confirmed_identity' => $confirmedIdentity,
                'changed_fields' => $this->getChangedFields($confirmedIdentity, $currentFields),
            ];
        }

        // Identity подтверждён и не требует перезапуска
        return [
            'should_rerun' => false,
            'reason' => null,
            'confirmed_identity' => $confirmedIdentity,
        ];
    }

    /**
     * Инвалидировать подтвержденную идентичность
     * (вызывается при изменении влияющих полей)
     */
    public function invalidateConfirmedIdentity(Exam $exam, string $reason = 'Fields changed'): void
    {
        $confirmedIdentities = $exam->confirmedIdentities()
            ->where('is_valid', true)
            ->get();

        foreach ($confirmedIdentities as $confirmed) {
            $confirmed->invalidate();

            Log::info('ConfirmedIdentity invalidated', [
                'exam_id' => $exam->id,
                'confirmed_identity_id' => $confirmed->id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Извлечь текущие значения влияющих полей
     */
    protected function extractSourceFields(Exam $exam): array
    {
        $fields = [];

        foreach (self::IDENTITY_AFFECTING_FIELDS as $field) {
            $fields[$field] = $exam->{$field} ?? null;
        }

        // Добавляем хеш документов (если есть)
        $documentIds = $exam->documents()->pluck('id')->toArray();
        $fields['document_ids_hash'] = md5(json_encode($documentIds));

        return $fields;
    }

    /**
     * Получить список изменившихся полей
     */
    protected function getChangedFields(ConfirmedIdentity $confirmedIdentity, array $currentFields): array
    {
        $sourceFields = $confirmedIdentity->source_fields ?? [];
        $changed = [];

        foreach ($sourceFields as $field => $oldValue) {
            $newValue = $currentFields[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Получить последнюю подтвержденную идентичность (даже если инвалидирована)
     */
    public function getLastConfirmedIdentity(Exam $exam): ?ConfirmedIdentity
    {
        return $exam->confirmedIdentities()
            ->orderBy('confirmed_at', 'desc')
            ->first();
    }

    /**
     * Проверить, совпадает ли текущая идентичность с подтвержденной
     * (для информирования пользователя)
     *
     * @return array ['matches' => bool, 'differences' => array]
     */
    public function compareWithConfirmed(Exam $exam, array $newIdentityData): array
    {
        $confirmedIdentity = $exam->confirmedIdentity()
            ->where('is_valid', true)
            ->first();

        if (! $confirmedIdentity) {
            return ['matches' => false, 'differences' => ['No confirmed identity']];
        }

        $confirmedData = $confirmedIdentity->identity_data;
        $differences = [];

        // Сравниваем canonical
        $confirmedCanonical = $confirmedData['canonical'] ?? [];
        $newCanonical = $newIdentityData['canonical'] ?? [];

        foreach (['exam_name', 'family', 'provider', 'level'] as $key) {
            $oldValue = $confirmedCanonical[$key] ?? null;
            $newValue = $newCanonical[$key] ?? null;
            if ($oldValue !== $newValue) {
                $differences[] = "$key: '$oldValue' → '$newValue'";
            }
        }

        return [
            'matches' => empty($differences),
            'differences' => $differences,
        ];
    }
}
