<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $exam_id
 * @property string|null $key
 * @property string $name
 * @property array|null $meta
 * @property string|null $description
 * @property int|null $order
 * @property string|null $skill
 * @property int|null $duration_min
 * @property float|null $max_score
 * @property float|null $min_pass_percent
 * @property string|null $time_policy
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * Virtual properties (from meta):
 * @property array|null $assembly_config
 * @property array $question_templates
 * @property string|null $slug Deprecated
 * @property array|null $question_types Deprecated
 * @property-read Exam $exam
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExamExampleQuestion> $examples
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Question> $questions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QuestionGroup> $questionGroups
 */
class ExamCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ExamCategoryFactory> */
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

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Exam, covariant self>
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ExamExampleQuestion, covariant self>
     */
    public function examples()
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Question, covariant self>
     */
    public function questions()
    {
        return $this->hasMany(Question::class, 'section_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<QuestionGroup, covariant self>
     */
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
