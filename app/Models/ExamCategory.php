<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'key', 'name', 'meta', 'description', 'order',
        // V2 fields
        'skill', 'duration_min', 'max_score', 'min_pass_percent', 'time_policy',
    ];

    protected $casts = [
        'meta' => AsArrayWithUnescapedSlashes::class,
        'question_archetypes' => 'array',
        'duration_min' => 'integer',
        'max_score' => 'decimal:2',
        'min_pass_percent' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function examples()
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'section_id');
    }

    public function questionGroups()
    {
        return $this->hasMany(QuestionGroup::class, 'section_id')->orderBy('order');
    }

    // ========== V2 ACCESSORS ==========

    /**
     * Get assembly config from meta['assembly']
     * Used in v2 for question generation (pool/blueprint/inline)
     */
    public function getAssemblyConfigAttribute(): ?array
    {
        return $this->meta['assembly'] ?? null;
    }

    /**
     * Set assembly config to meta['assembly']
     */
    public function setAssemblyConfigAttribute(array $value): void
    {
        $meta = $this->meta ?? [];
        $meta['assembly'] = $value;
        $this->meta = $meta;
    }

    // ========== V1 ACCESSORS (Backward Compatibility) ==========

    /**
     * Get question templates for this category with overrides applied
     * Implements Variant A (Global templates + references)
     * NOTE: This is v1 compatibility accessor
     *
     * @return array
     */
    public function getQuestionTemplatesAttribute(): array
    {
        // Get global templates from exam structure (single source of truth)
        $globalTemplates = $this->exam->meta['exam_structure']['question_archetypes'] ?? [];

        // Get this category's question sequence (references)
        $sequence = $this->meta['question_sequence'] ?? [];

        // Get per-category overrides (optional)
        $overrides = $this->meta['question_overrides'] ?? [];

        // Build final templates array with overrides applied
        return collect($sequence)
            ->map(function ($ref) use ($globalTemplates, $overrides) {
                $templateId = $ref['template_id'];
                $template = $globalTemplates[$templateId] ?? [];
                $override = $overrides[$templateId] ?? [];

                return array_merge($template, $override, [
                    'order' => $ref['order'],
                ]);
            })
            ->sortBy('order')
            ->values()
            ->all();
    }
}
