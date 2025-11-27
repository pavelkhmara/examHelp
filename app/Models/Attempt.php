<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $exam_id
 * @property string|null $user_id
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property float|null $score
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Exam $exam
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AttemptAnswer> $answers
 *
 * @uses HasFactory<\Database\Factories\AttemptFactory>
 */
class Attempt extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['exam_id', 'user_id', 'started_at', 'completed_at', 'score'];

    /**
     * @return BelongsTo<Exam, covariant self>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return HasMany<AttemptAnswer, covariant self>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }
}
