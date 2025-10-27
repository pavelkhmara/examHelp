<?php

namespace App\Models;

use App\Domain\Questions\Validation\QuestionPayloadValidator;
use App\Domain\Taxonomy\QuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamExampleQuestion extends Model
{
    use HasFactory;

    protected $table = 'exam_example_questions';

    protected $fillable = [
        'exam_id', 'exam_category_id', 'question',
        'description', 'duration_minutes', 'instructions', 'example_response', 'assessment_guide',
        'good_answer', 'average_answer', 'bad_answer', 'rubric_breakdown',
        'type', 'payload',
    ];

    protected $casts = [
        'good_answer' => 'json',
        'average_answer' => 'json',
        'bad_answer' => 'json',
        'rubric_breakdown' => 'json',
        'payload' => 'json',
        'example_response' => 'json',
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
            // ВСЕГДА берём строковое значение типа
            $val = is_string($model->type) ? $model->type : ($model->type?->value ?? null);

            $type = (string) ($val ?? '');

            $payload = (array) ($model->payload ?? []);

            QuestionPayloadValidator::validate($type, $payload);

            if (! in_array($val, QuestionType::all(), true)) {
                throw new \InvalidArgumentException('Unknown question type: '.($val ?? 'null'));
            }

            // если у тебя ниже есть присваивания, оставь как было
            $model->type = $val;
            $model->payload = $payload;
        });
    }
}
