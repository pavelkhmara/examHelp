<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Question Group Model
 *
 * Represents a group of questions sharing common stimulus and settings.
 * Used for listening/reading tasks where multiple questions refer to same audio/text.
 *
 * Example: Polish exam Task I has 6 questions sharing one audio file.
 *
 * @property int $id
 * @property string $exam_id
 * @property int $section_id
 * @property string $group_id
 * @property string|null $title
 * @property array|null $instructions
 * @property array|null $stimulus
 * @property array|null $playback_settings
 * @property array|null $metadata
 * @property int $order
 */
class QuestionGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'section_id',
        'group_id',
        'title',
        'instructions',
        'stimulus',
        'playback_settings',
        'metadata',
        'order',
    ];

    protected $casts = [
        'instructions' => 'array',
        'stimulus' => 'array',
        'playback_settings' => 'array',
        'metadata' => 'array',
        'order' => 'integer',
    ];

    // ========== RELATIONSHIPS ==========

    /**
     * @return BelongsTo<Exam>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * @return BelongsTo<ExamCategory>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamCategory::class, 'section_id');
    }

    /**
     * @return HasMany<Question>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'question_group_id');
    }

    // ========== ACCESSORS ==========

    /**
     * Get audio URLs from stimulus
     */
    public function getAudioUrlsAttribute(): array
    {
        return $this->stimulus['audio'] ?? [];
    }

    /**
     * Get max playback count (null = unlimited)
     */
    public function getMaxPlaysAttribute(): ?int
    {
        return $this->playback_settings['max_plays'] ?? null;
    }

    /**
     * Get playback enforcement mode
     */
    public function getPlaybackEnforcementAttribute(): string
    {
        return $this->playback_settings['enforcement'] ?? 'none';
    }

    /**
     * Check if playback is limited
     */
    public function hasPlaybackLimit(): bool
    {
        return ! is_null($this->max_plays);
    }

    /**
     * Check if enforcement is strict
     */
    public function isStrictEnforcement(): bool
    {
        return $this->playback_enforcement === 'strict';
    }

    /**
     * Get stimulus text HTML
     */
    public function getStimulusTextAttribute(): ?string
    {
        return $this->stimulus['text_html'] ?? null;
    }

    /**
     * Get stimulus images
     */
    public function getStimulusImagesAttribute(): array
    {
        return $this->stimulus['images'] ?? [];
    }

    /**
     * Get stimulus video URLs
     */
    public function getStimulusVideosAttribute(): array
    {
        return $this->stimulus['video'] ?? [];
    }

    // ========== VALIDATION ==========

    /**
     * Validate question group structure
     *
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        // Минимум 2 вопроса в группе
        $questionCount = $this->questions()->count();
        if ($questionCount < 2) {
            throw new \InvalidArgumentException(
                "Question group must have at least 2 questions. Group '{$this->group_id}' has {$questionCount}."
            );
        }

        // Проверка playback_settings
        if (! empty($this->playback_settings)) {
            $maxPlays = $this->playback_settings['max_plays'] ?? null;
            if (! is_null($maxPlays) && (! is_int($maxPlays) || $maxPlays < 1)) {
                throw new \InvalidArgumentException(
                    'max_plays must be a positive integer or null. Got: '.var_export($maxPlays, true)
                );
            }

            $enforcement = $this->playback_settings['enforcement'] ?? 'none';
            if (! in_array($enforcement, ['strict', 'advisory', 'none'], true)) {
                throw new \InvalidArgumentException(
                    "Invalid enforcement mode: {$enforcement}. Allowed: strict, advisory, none."
                );
            }
        }
    }

    // ========== SCOPES ==========

    public function scopeForSection($query, int $sectionId)
    {
        return $query->where('section_id', $sectionId);
    }

    public function scopeForExam($query, string $examId)
    {
        return $query->where('exam_id', $examId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function scopeWithQuestions($query)
    {
        return $query->with('questions');
    }

    // ========== HELPER METHODS ==========

    /**
     * Get total questions count
     */
    public function getQuestionCountAttribute(): int
    {
        return $this->questions()->count();
    }

    /**
     * Check if group has audio stimulus
     */
    public function hasAudio(): bool
    {
        return ! empty($this->audio_urls);
    }

    /**
     * Check if group has text stimulus
     */
    public function hasText(): bool
    {
        return ! empty($this->stimulus_text);
    }

    /**
     * Check if group has images
     */
    public function hasImages(): bool
    {
        return ! empty($this->stimulus_images);
    }

    /**
     * Get formatted playback settings for UI
     */
    public function getPlaybackSettingsForUI(): array
    {
        return [
            'max_plays' => $this->max_plays,
            'enforcement' => $this->playback_enforcement,
            'show_counter' => $this->playback_settings['show_counter'] ?? true,
            'reset_on_navigation' => $this->playback_settings['reset_on_navigation'] ?? true,
        ];
    }
}
