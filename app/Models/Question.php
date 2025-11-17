<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Question Model - V2 Question Archetype
 *
 * Represents a question following the s2_exam_question_archetype_v2.json schema.
 * This replaces the old ExamExampleQuestion model.
 *
 * Core v2 fields:
 * - type: from enum (single_select, multi_select, true_false, etc.)
 * - skills_measured: array of skills being tested
 * - time_limit_sec: time allowed in seconds
 * - instructions: {brief, full, audio_url?, video_url?, l10n?}
 * - stimulus: {text_html?, images[], audio[], video[], resources?, media_metadata?}
 * - interaction: {response_type, options[]?, pairs[]?, bank[]?, spans[]?}
 * - response: {mode, max_words?, recording_limit_sec?, attempts?, validation?}
 * - scoring: {method, answer_key?, partial_rules[]?, rubric?, max_score?}
 * - metadata: {cefr[], difficulty, topic, language, sources[]?, tags[]?}
 */
class Question extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'exam_id',
        'section_id',
        'question_id',
        'type',
        'skills_measured',
        'time_limit_sec',
        // v2 structure fields (JSON)
        'instructions',
        'stimulus',
        'interaction',
        'response',
        'scoring',
        'metadata',
        // Optional v2 fields
        'constraints',
        'randomization',
        'outcome_reporting',
        'io_signature',
        'typical_errors',
        'ui_hints',
        'accessibility',
        // Status
        'status',
        // Audio fields
        'audio_file_path',
        'requires_audio',
    ];

    protected $casts = [
        'skills_measured' => AsArrayWithUnescapedSlashes::class,
        'instructions' => AsArrayWithUnescapedSlashes::class,
        'stimulus' => AsArrayWithUnescapedSlashes::class,
        'interaction' => AsArrayWithUnescapedSlashes::class,
        'response' => AsArrayWithUnescapedSlashes::class,
        'scoring' => AsArrayWithUnescapedSlashes::class,
        'metadata' => AsArrayWithUnescapedSlashes::class,
        'constraints' => AsArrayWithUnescapedSlashes::class,
        'randomization' => AsArrayWithUnescapedSlashes::class,
        'outcome_reporting' => AsArrayWithUnescapedSlashes::class,
        'io_signature' => AsArrayWithUnescapedSlashes::class,
        'typical_errors' => AsArrayWithUnescapedSlashes::class,
        'ui_hints' => AsArrayWithUnescapedSlashes::class,
        'accessibility' => AsArrayWithUnescapedSlashes::class,
        'time_limit_sec' => 'integer',
        'frozen_at' => 'datetime',
    ];

    // ========== RELATIONSHIPS ==========

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamCategory::class, 'section_id');
    }

    // ========== HELPERS ==========

    /**
     * Check if question is frozen (immutable)
     */
    public function isFrozen(): bool
    {
        return ! is_null($this->frozen_at);
    }

    /**
     * Freeze the question (make it immutable)
     */
    public function freeze(): void
    {
        $this->frozen_at = now();
        $this->save();
    }

    /**
     * Unfreeze the question (make it mutable again)
     */
    public function unfreeze(): void
    {
        $this->frozen_at = null;
        $this->save();
    }

    /**
     * Publish the question
     */
    public function publish(): void
    {
        $this->status = 'published';
        $this->save();
    }

    /**
     * Archive the question
     */
    public function archive(): void
    {
        $this->status = 'archived';
        $this->save();
    }

    // ========== SCOPES ==========

    /**
     * Scope to only published questions
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to only draft questions
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to only frozen questions
     */
    public function scopeFrozen($query)
    {
        return $query->whereNotNull('frozen_at');
    }

    /**
     * Scope to questions by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
