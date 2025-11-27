<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $generation_task_id
 * @property string|null $exam_id
 * @property string|null $stage
 * @property string|null $model
 * @property string|null $model_alias
 * @property array|null $request
 * @property array|null $response
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $total_tokens
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * Virtual properties:
 * @property int|null $duration_ms Computed from timestamps
 * @property-read Exam|null $exam
 * @property-read GenerationTask|null $task
 */
class GenerationLog extends Model
{
    protected $fillable = [
        'generation_task_id', 'stage', 'model', 'model_alias', 'request', 'response',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
    ];

    protected $casts = [
        'exam_id' => 'string',
        'request' => AsArrayWithUnescapedSlashes::class,
        'response' => AsArrayWithUnescapedSlashes::class,
    ];

    /**
     * @return BelongsTo<Exam>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * @return BelongsTo<GenerationTask>
     */
    public function task()
    {
        return $this->belongsTo(GenerationTask::class, 'generation_task_id');
    }

    protected static function booted()
    {
        static::creating(function ($log) {
            if (empty($log->exam_id) && ! empty($log->generation_task_id)) {
                $task = \App\Models\GenerationTask::find($log->generation_task_id);
                if ($task && $task->exam_id) {
                    $log->exam_id = $task->exam_id;
                }
            }
        });
    }
}
