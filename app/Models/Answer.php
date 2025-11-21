<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    protected $fillable = [
        'session_id',
        'question_id',
        'answer',
        'evaluation',
        'is_correct',
        'points_earned',
        'points_possible',
        'time_spent_sec',
        'attempt',
    ];

    protected $casts = [
        'answer' => 'array',
        'evaluation' => 'array',
        'is_correct' => 'boolean',
        'points_earned' => 'decimal:2',
        'points_possible' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
