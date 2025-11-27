<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @uses HasFactory<\Database\Factories\QuestionOptionFactory>
 */
class QuestionOption extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['question_id', 'text', 'is_correct'];

    /**
     * @return BelongsTo<Question, covariant self>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
