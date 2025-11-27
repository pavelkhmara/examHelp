<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'exam_id',
        'user_id',
        'device_id',
        'mode',
        'settings',
        'started_at',
        'finished_at',
        'result',
    ];

    protected $casts = [
        'settings' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Exam, covariant self>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return HasMany<Answer, covariant self>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'session_id');
    }

    /**
     * @return HasMany<Recommendation, covariant self>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'session_id');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function isPracticeMode(): bool
    {
        return $this->mode === 'practice';
    }

    public function isExamMode(): bool
    {
        return $this->mode === 'exam';
    }

    /**
     * Calculate total score from answers.
     */
    public function calculateScore(): array
    {
        $answers = $this->answers()->whereNotNull('evaluation')->get();

        $totalPoints = 0;
        $maxPoints = 0;
        $correct = 0;
        $incorrect = 0;

        foreach ($answers as $answer) {
            $totalPoints += $answer->points_earned ?? 0;
            $maxPoints += $answer->points_possible ?? 0;

            if ($answer->is_correct) {
                $correct++;
            } else {
                $incorrect++;
            }
        }

        return [
            'total_points' => $totalPoints,
            'max_points' => $maxPoints,
            'percentage' => $maxPoints > 0 ? round(($totalPoints / $maxPoints) * 100, 1) : 0,
            'correct' => $correct,
            'incorrect' => $incorrect,
            'total_questions' => $answers->count(),
        ];
    }
}
