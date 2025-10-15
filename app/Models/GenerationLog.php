<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationLog extends Model
{
    protected $fillable = [
        'generation_task_id','stage','request','response',
        'prompt_tokens','completion_tokens','total_tokens',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function task() {
        return $this->belongsTo(GenerationTask::class, 'generation_task_id');
    }
}
