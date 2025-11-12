<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generation Plan Model
 *
 * Tracks generation plans for exam sections based on Phase B assembly configs.
 * Each plan represents how questions will be generated for a specific section.
 *
 * @property int $id
 * @property string $exam_id
 * @property string $section_id
 * @property string $assembly_mode pool|blueprint|inline
 * @property array $plan_data
 * @property string $status pending|in_progress|completed|failed|partial
 * @property int $total_questions
 * @property int $generated_questions
 * @property string|null $error
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Exam $exam
 * @property-read float $progress_percent
 * @property-read bool $is_completed
 * @property-read bool $is_failed
 */
class GenerationPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'section_id',
        'assembly_mode',
        'plan_data',
        'status',
        'total_questions',
        'generated_questions',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'plan_data' => 'array',
        'total_questions' => 'integer',
        'generated_questions' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the exam that owns this generation plan
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * Get progress percentage (0-100)
     */
    public function getProgressPercentAttribute(): float
    {
        if ($this->total_questions === 0) {
            return 0.0;
        }

        return round(($this->generated_questions / $this->total_questions) * 100, 2);
    }

    /**
     * Check if generation is completed
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if generation failed
     */
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Scope: Filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter by assembly mode
     */
    public function scopeAssemblyMode($query, string $mode)
    {
        return $query->where('assembly_mode', $mode);
    }

    /**
     * Scope: Filter by section
     */
    public function scopeSection($query, string $sectionId)
    {
        return $query->where('section_id', $sectionId);
    }

    /**
     * Scope: Get pending plans
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Get in-progress plans
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope: Get completed plans
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: Get failed plans
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Mark plan as in progress
     */
    public function markAsInProgress(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark plan as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'generated_questions' => $this->total_questions,
        ]);
    }

    /**
     * Mark plan as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update generation progress
     */
    public function updateProgress(int $generatedCount): void
    {
        $this->update([
            'generated_questions' => $generatedCount,
        ]);
    }

    /**
     * Increment generated questions counter
     */
    public function incrementGenerated(int $count = 1): void
    {
        $this->increment('generated_questions', $count);
    }

    /**
     * Get plan data for specific assembly mode
     */
    public function getPlanDataTyped(): array
    {
        return match ($this->assembly_mode) {
            'pool' => $this->getPoolPlanData(),
            'blueprint' => $this->getBlueprintPlanData(),
            'inline' => $this->getInlinePlanData(),
            default => $this->plan_data,
        };
    }

    /**
     * Get pool plan data
     */
    private function getPoolPlanData(): array
    {
        return [
            'pool_id' => $this->plan_data['pool_id'] ?? null,
            'filters' => $this->plan_data['filters'] ?? [],
            'pick' => $this->plan_data['pick'] ?? 0,
            'seed' => $this->plan_data['seed'] ?? null,
        ];
    }

    /**
     * Get blueprint plan data
     */
    private function getBlueprintPlanData(): array
    {
        return [
            'slots' => $this->plan_data['slots'] ?? [],
        ];
    }

    /**
     * Get inline plan data
     */
    private function getInlinePlanData(): array
    {
        return [
            'placeholders' => $this->plan_data['placeholders'] ?? [],
        ];
    }
}
