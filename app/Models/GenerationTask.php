<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $status Task status: queued, running, completed, failed, pending_confirmation, pending_clarification, cancelled, waiting_for_confirmation
 */
class GenerationTask extends Model
{
    use HasFactory;
    protected $fillable = [
        'exam_id', 'type', 'status',
        'request', 'response', 'result',
        'error', 'attempts',
        'idempotency_key', 'activities', 'heartbeat_at',
    ];

    protected $casts = [
        'request' => AsArrayWithUnescapedSlashes::class,
        'response' => AsArrayWithUnescapedSlashes::class,
        'result' => AsArrayWithUnescapedSlashes::class,
        'activities' => AsArrayWithUnescapedSlashes::class,
        'heartbeat_at' => 'datetime',
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

        // Prevent duplicate activities with same event within last 10 seconds
        $now = now();
        foreach ($activities as $existingActivity) {
            if (($existingActivity['event'] ?? '') === $event) {
                try {
                    $existingTime = new \DateTime($existingActivity['timestamp'] ?? '');
                    $diffSeconds = $now->diffInSeconds($existingTime);
                    if ($diffSeconds < 10) {
                        // Duplicate detected - skip adding
                        \Illuminate\Support\Facades\Log::debug('Skipping duplicate activity', [
                            'event' => $event,
                            'task_id' => $this->id,
                            'time_diff_seconds' => $diffSeconds,
                        ]);
                        return;
                    }
                } catch (\Exception $e) {
                    // Continue if timestamp parsing fails
                }
            }
        }

        $activity = [
            'timestamp' => now()->toISOString(), // Храним ISO формат в БД
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

    /**
     * Форматировать timestamp из ISO 8601 в [DD.MM HH:MM:SS]
     */
    public static function formatActivityTimestamp(string $isoTimestamp): string
    {
        try {
            $dt = new \DateTime($isoTimestamp);

            return '['.$dt->format('d.m H:i:s').']';
        } catch (\Exception $e) {
            return '[Invalid timestamp]';
        }
    }

    /**
     * Получить отформатированные активности для отображения
     */
    public function getFormattedActivities(): array
    {
        $activities = $this->activities ?? [];
        $formatted = [];

        foreach ($activities as $activity) {
            $timestamp = $activity['timestamp'] ?? null;
            $formatted[] = [
                'timestamp' => $timestamp ? self::formatActivityTimestamp($timestamp) : '[Unknown]',
                'timestamp_iso' => $timestamp,
                'event' => $activity['event'] ?? 'unknown',
                'message' => $activity['message'] ?? '',
                'context' => $activity['context'] ?? null,
            ];
        }

        return $formatted;
    }

    /**
     * Получить текущий прогресс выполнения задачи
     * Возвращает строку вида "Идёт: Overview · 02:15"
     */
    public function getCurrentProgress(): ?string
    {
        if ($this->status !== 'running') {
            return null;
        }

        // Определяем текущий stage из activities
        $activities = $this->activities ?? [];
        $lastActivity = end($activities);
        $currentStage = 'Processing'; // По умолчанию

        if ($lastActivity) {
            $event = $lastActivity['event'] ?? '';
            // Извлекаем stage из event (например, 'identity_guard_started' -> 'Identity')
            if (str_contains($event, 'identity')) {
                $currentStage = 'Identity';
            } elseif (str_contains($event, 'overview') || str_contains($event, 'structure')) {
                $currentStage = 'Overview';
            } elseif (str_contains($event, 'example')) {
                $currentStage = 'Examples';
            }
        }

        // Вычисляем длительность
        $startTime = $this->created_at;
        $duration = now()->diff($startTime);
        $durationStr = sprintf('%02d:%02d', $duration->i, $duration->s);

        return "Идёт: {$currentStage} · {$durationStr}";
    }

    /**
     * Update heartbeat timestamp
     * Call this periodically during long-running operations to prevent stall detection
     */
    public function updateHeartbeat(): void
    {
        $this->heartbeat_at = now();
        $this->saveQuietly(); // Use saveQuietly to avoid triggering observers
    }
}
