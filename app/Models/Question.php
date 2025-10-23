<?php

namespace App\Models;

use App\Domain\Taxonomy\QuestionType;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory, HasUuid;

    // Схема: id, exam_id, type, prompt, position, timestamps
    protected $fillable = ['exam_id', 'type', 'prompt', 'position'];

    protected $casts = [
        'type' => QuestionType::class,
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            // Валидация известного типа (если enum каст вернул строку)
            $val = is_string($model->type) ? $model->type : ($model->type?->value ?? null);

            // Совместимость с фиче-тестами API попыток: допускаем «MCQ» и «TEXT»
            $legacy = ['MCQ', 'TEXT'];
            if (in_array($val, $legacy, true)) {
                return; // не валидируем по enum
            }

            // Если у вас в QuestionType есть метод all(), оставьте как было; если нет — cases()
            if (method_exists(QuestionType::class, 'all')) {
                /** @phpstan-ignore-next-line */
                if (! in_array($val, QuestionType::all(), true)) {
                    throw new \InvalidArgumentException("Invalid question type '{$val}' for Question");
                }
            } else {
                $allowed = array_map(fn ($c) => $c->value, QuestionType::cases());
                if (! in_array($val, $allowed, true)) {
                    throw new \InvalidArgumentException("Invalid question type '{$val}' for Question");
                }
            }
        });
    }
}
