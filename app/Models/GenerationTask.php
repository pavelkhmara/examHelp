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
        'idempotency_key', 'activities',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'result' => 'array',
        'activities' => 'array',
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

    /**
     * Add an activity entry to track task progress
     *
     * @param  string  $event  Event type (e.g., 'task_created', 'identity_guard_started', 'confidence_low')
     * @param  string  $message  Human-readable message for Nova UI
     * @param  array  $context  Optional additional context data
     */
    public function addActivity(string $event, string $message, array $context = []): void
    {
        $activities = $this->activities ?? [];

        $activity = [
            'timestamp' => now()->toISOString(),
            'event' => $event,
            'message' => $message,
        ];

        if (! empty($context)) {
            $activity['context'] = $context;
        }

        $activities[] = $activity;

        $this->activities = $activities;
        $this->save();
    }
}
