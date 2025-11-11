<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamCategory extends Model
{
    use HasFactory;

    protected $fillable = ['exam_id', 'key', 'name', 'meta', 'description', 'order'];

    protected $casts = ['meta' => AsArrayWithUnescapedSlashes::class];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function examples()
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    /**
     * Get question templates for this category with overrides applied
     * Implements Variant A (Global templates + references)
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
