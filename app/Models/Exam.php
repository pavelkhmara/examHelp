<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Exam extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'slug','title','description','level','is_active',
        'sources','meta','research_status',
        'categories_count','examples_count',
    ];

    protected $casts = [
        'sources' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(ExamCategory::class,'exam_id', 'id');
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

    public function loadAllCounts()
    {
        return $this->loadCount([
            'categories', 
            'examples', 
            'generationTasks',
            'generationLogs'
        ]);
    }

    // Упрощённая структура экзамена из meta
    public function getExamStructureAttribute()
    {
        return $this->meta['exam_structure'] ?? null;
    }

    // Суммарная длительность из структуры (если есть)
    public function getTotalExamDurationAttribute()
    {
        return data_get($this->exam_structure, 'total_exam_duration');
    }

    // Удобный список секций (категорий) из структуры
    public function getStructureSectionsAttribute()
    {
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
        return [];
    }
}
