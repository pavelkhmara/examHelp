<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\Questions\Validation\QuestionPayloadValidator;
use App\Domain\Taxonomy\QuestionType;

class ExamExampleQuestion extends Model
{
    use HasFactory;

    protected $table = 'exam_example_questions';

    protected $fillable = [
        'exam_id', 'exam_category_id', 'question',
        'good_answer', 'average_answer', 'bad_answer', 'rubric_breakdown',
        'type', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'type' => QuestionType::class,
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(ExamCategory::class, 'exam_category_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $val = is_string($model->type) ? $model->type : ($model->type?->value ?? null);
            $type = (string) $model->type;
            $payload = (array) ($model->payload ?? []);
            // строгая валидация payload под тип
            QuestionPayloadValidator::validate($type, $payload);
            if (!in_array($val, QuestionType::all(), true)) {
                throw new \InvalidArgumentException("Invalid question type '{$val}' for ExamExampleQuestion");
            }
        });
    }
}
