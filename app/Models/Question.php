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
 *
 * @property int $id
 * @property string $exam_id
 * @property int $section_id
 * @property int|null $question_group_id
 * @property int|null $order
 * @property string|null $question_id
 * @property string $type
 * @property array|null $skills_measured
 * @property int|null $time_limit_sec
 * @property array|null $instructions
 * @property array|null $stimulus
 * @property array|null $interaction
 * @property array|null $response
 * @property array|null $scoring
 * @property array|null $metadata
 * @property array|null $constraints
 * @property array|null $randomization
 * @property array|null $outcome_reporting
 * @property array|null $io_signature
 * @property array|null $typical_errors
 * @property array|null $ui_hints
 * @property array|null $accessibility
 * @property string|null $status
 * @property string|null $audio_file_path
 * @property bool|null $requires_audio
 * @property string|null $image_url
 * @property string|null $image_file_path
 * @property array|null $image_alternatives
 * @property array|null $image_metadata
 * @property \Carbon\Carbon|null $frozen_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * Virtual properties (from JSON fields):
 * @property mixed $options Deprecated: from interaction field
 * @property string|null $prompt Deprecated: from instructions field
 * @property int|null $position Deprecated: from order field
 * @property-read Exam $exam
 * @property-read ExamCategory $section
 * @property-read QuestionGroup|null $questionGroup
 */
class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'exam_id',
        'section_id',
        'question_group_id',
        'order',
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
        // Image fields
        'image_url',
        'image_file_path',
        'image_alternatives',
        'image_metadata',
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
        'image_alternatives' => AsArrayWithUnescapedSlashes::class,
        'image_metadata' => AsArrayWithUnescapedSlashes::class,
        'time_limit_sec' => 'integer',
        'requires_audio' => 'boolean',
        'frozen_at' => 'datetime',
        'status' => 'string',
    ];

    // ========== RELATIONSHIPS ==========

    /**
     * @return BelongsTo<Exam, covariant self>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * @return BelongsTo<ExamCategory, covariant self>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamCategory::class, 'section_id');
    }

    /**
     * @return BelongsTo<QuestionGroup, covariant self>
     */
    public function questionGroup(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id');
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
     * Check if question belongs to a group
     */
    public function isGrouped(): bool
    {
        return ! is_null($this->question_group_id);
    }

    /**
     * Get resolved stimulus (group stimulus merged with question stimulus)
     *
     * Priority: question stimulus overrides/extends group stimulus
     */
    public function getResolvedStimulus(): array
    {
        $stimulus = [];

        // 1. Start with group stimulus (if exists)
        if ($this->isGrouped() && $this->questionGroup) {
            $stimulus = $this->questionGroup->stimulus ?? [];
        }

        // 2. Merge with question stimulus (question overrides group)
        $questionStimulus = $this->stimulus ?? [];

        foreach ($questionStimulus as $key => $value) {
            if (is_array($value) && isset($stimulus[$key]) && is_array($stimulus[$key])) {
                // Array merge for audio, images, video arrays
                $stimulus[$key] = array_merge($stimulus[$key], $value);
            } else {
                // Direct override for text_html and other scalar values
                $stimulus[$key] = $value;
            }
        }

        return $stimulus;
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
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopePublished($query): mixed
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to only draft questions
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeDraft($query): mixed
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to only frozen questions
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeFrozen($query): mixed
    {
        return $query->whereNotNull('frozen_at');
    }

    /**
     * Scope to questions by type
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeOfType($query, string $type): mixed
    {
        return $query->where('type', $type);
    }
}
