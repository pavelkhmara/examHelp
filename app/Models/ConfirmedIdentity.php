<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подтверждённая идентичность экзамена
 *
 * Отдельное хранение позволяет:
 * - Отслеживать, что Identity был подтверждён пользователем/системой
 * - Не требовать повторного запуска, пока не изменились влияющие поля
 * - Сохранять историю подтверждений
 */
class ConfirmedIdentity extends Model
{
    protected $fillable = [
        'exam_id',
        'identity_data',
        'source_fields',
        'confirmed_at',
        'confirmed_by_task_id',
        'is_valid',
    ];

    protected $casts = [
        'identity_data' => AsArrayWithUnescapedSlashes::class,
        'source_fields' => AsArrayWithUnescapedSlashes::class,
        'confirmed_at' => 'datetime',
        'is_valid' => 'boolean',
    ];

    /**
     * Exam к которому относится подтверждённая идентичность
     *
     * @return BelongsTo<Exam>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * Задача (GenerationTask), которая создала эту подтверждённую идентичность
     *
     * @return BelongsTo<GenerationTask>
     */
    public function confirmedByTask(): BelongsTo
    {
        return $this->belongsTo(GenerationTask::class, 'confirmed_by_task_id');
    }

    /**
     * Инвалидировать подтверждённую идентичность
     * (вызывается при изменении влияющих полей)
     */
    public function invalidate(): void
    {
        $this->is_valid = false;
        $this->save();
    }

    /**
     * Проверить, изменились ли влияющие поля экзамена
     *
     * @param  array  $currentFields  Текущие значения полей экзамена
     */
    public function hasSourceFieldsChanged(array $currentFields): bool
    {
        $sourceFields = $this->source_fields ?? [];

        foreach ($sourceFields as $field => $value) {
            // FIX: Use array_key_exists instead of isset to handle NULL values correctly
            // isset() returns false for NULL values, causing false positives
            if (! array_key_exists($field, $currentFields) || $currentFields[$field] !== $value) {
                return true;
            }
        }

        return false;
    }
}
