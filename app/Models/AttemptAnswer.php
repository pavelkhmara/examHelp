<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @uses HasFactory<\Database\Factories\AttemptAnswerFactory>
 */
class AttemptAnswer extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['attempt_id', 'question_id', 'selected_option_id', 'text_answer', 'is_correct'];

    /**
     * @return BelongsTo<Attempt, covariant self>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /**
     * @return BelongsTo<Question, covariant self>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return BelongsTo<QuestionOption, covariant self>
     */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }
}
