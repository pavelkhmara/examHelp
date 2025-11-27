<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Exam extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug', 'title', 'description', 'level', 'language_of_test', 'is_active',
        'user_input', 'user_meta', 'identity', 'system_analysis', 'analysis_status',
        'sources', 'meta', 'research_status',
        'categories_count', 'examples_count',
        'document_upload',
    ];

    protected $casts = [
        'user_meta' => AsArrayWithUnescapedSlashes::class,
        'identity' => AsArrayWithUnescapedSlashes::class,
        'system_analysis' => AsArrayWithUnescapedSlashes::class,
        'sources' => AsArrayWithUnescapedSlashes::class,
        'meta' => AsArrayWithUnescapedSlashes::class,
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(ExamCategory::class, 'exam_id', 'id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(ExamExampleQuestion::class);
    }

    public function generationTasks(): HasMany
    {
        return $this->hasMany(GenerationTask::class, 'exam_id', 'id');
    }

    public function generationLogs(): HasMany
    {
        return $this->hasMany(GenerationLog::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ExamDocument::class);
    }

    public function confirmedIdentity(): HasOne
    {
        return $this->hasOne(ConfirmedIdentity::class, 'exam_id', 'id');
    }

    public function confirmedIdentities(): HasMany
    {
        return $this->hasMany(ConfirmedIdentity::class, 'exam_id', 'id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'exam_id', 'id');
    }

    public function questionGroups(): HasMany
    {
        return $this->hasMany(QuestionGroup::class, 'exam_id', 'id');
    }

    public function loadAllCounts()
    {
        return $this->loadCount([
            'categories',
            'examples',
            'generationTasks',
            'generationLogs',
            'questions',
            'questionGroups',
        ]);
    }

    // ========== V2 ACCESSORS ==========

    /**
     * Get v2 exam structure from meta['structure_v2']
     *
     * @deprecated Use ExamCategory as primary source of truth for section data.
     *             structure_v2 is now a STAGING area used during generation.
     *             After finalize, read from $exam->categories instead.
     *             See: docs/fixes/fix-dual-source-of-truth.md
     *
     * This is the new v2 format with sections[], pass_policy, policies, etc.
     */
    public function getStructureV2Attribute(): ?array
    {
        return $this->meta['structure_v2'] ?? null;
    }

    /**
     * Set v2 exam structure to meta['structure_v2']
     *
     * @deprecated For new data, prefer writing to ExamCategory.
     *             structure_v2 should only be used as staging during generation.
     */
    public function setStructureV2Attribute(array $value): void
    {
        $meta = $this->meta ?? [];
        $meta['structure_v2'] = $value;
        $this->meta = $meta;
    }

    // ========== V1 ACCESSORS (Backward Compatibility) ==========

    /**
     * Get v1 exam structure from meta['exam_structure'] (for backward compatibility)
     */
    public function getExamStructureAttribute()
    {
        return $this->meta['exam_structure'] ?? null;
    }

    /**
     * Get total exam duration from v1 structure (backward compatibility)
     */
    public function getTotalExamDurationAttribute()
    {
        return data_get($this->exam_structure, 'total_exam_duration');
    }

    /**
     * Get sections from v1 structure (backward compatibility)
     */
    public function getStructureSectionsAttribute()
    {
        // NEW v2: Try structure_v2 first (stored in meta)
        $v2 = $this->meta['structure_v2'] ?? null;
        if (is_array($v2) && isset($v2['sections']) && is_array($v2['sections'])) {
            return $v2['sections'];
        }

        // LEGACY v1: Fallback to old exam_structure
        $s = $this->exam_structure;
        if (is_array($s)) {
            // поддержка как объекта с sections, так и массива верхнего уровня
            if (isset($s['sections']) && is_array($s['sections'])) {
                return $s['sections'];
            }
            if (isset($s[0]) && is_array($s[0])) {
                // если exam_structure — массив секций без ключа 'sections'
                return $s;
            }
        }

        // REFERENCE EXAMS: Load from database tables (ExamCategory, QuestionGroup, Question)
        $categories = $this->categories()
            ->with(['questionGroups' => fn ($q) => $q->orderBy('order'), 'questionGroups.questions' => fn ($q) => $q->orderBy('order')])
            ->orderBy('order')
            ->get();
        if ($categories->isNotEmpty()) {
            return $categories->map(function ($category) {
                return [
                    'key' => $category->key,
                    'name' => $category->name,
                    'skill' => $category->skill,
                    'duration_min' => $category->duration_min,
                    'max_score' => $category->max_score,
                    'description' => $category->description,
                    'assembly' => [
                        'mode' => 'inline',
                        'question_groups' => $category->questionGroups->sortBy('order')->values()->map(function ($group) {
                            return [
                                'id' => $group->group_id,
                                'title' => $group->title,
                                'order' => $group->order,
                                'instructions' => $group->instructions,
                                'stimulus' => $group->stimulus,
                                'playback_settings' => $group->playback_settings,
                                'metadata' => $group->metadata,
                                'questions' => $group->questions->sortBy('order')->values()->map(function ($q) {
                                    return [
                                        'id' => $q->question_id,
                                        'type' => $q->type,
                                        'order' => $q->order,
                                        'instructions' => $q->instructions,
                                        'stimulus' => $q->stimulus,
                                        'interaction' => $q->interaction,
                                        'scoring' => $q->scoring,
                                        'metadata' => $q->metadata,
                                    ];
                                })->toArray(),
                            ];
                        })->toArray(),
                    ],
                ];
            })->toArray();
        }

        return [];
    }
}
