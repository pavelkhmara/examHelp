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
        'exam_id' => 'string',
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

    protected static function booted()
    {
        static::creating(function ($log) {
            if (empty($log->exam_id) && !empty($log->generation_task_id)) {
                $task = \App\Models\GenerationTask::find($log->generation_task_id);
                if ($task && $task->exam_id) {
                    $log->exam_id = $task->exam_id;
                }
            }
        });
    }

}
