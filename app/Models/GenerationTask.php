<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GenerationTask extends Model
{
    protected $fillable = [
        'exam_id', 'type', 'status', 
        'request', 'response', 'result',
        'error', 'attempts',
        'idempotency_key',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'result' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function logs()
    {
        return $this->hasMany(GenerationLog::class);
    }
}
