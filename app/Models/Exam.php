<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Exam extends Model
{
    use HasUuids;

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

    public function categories() {
        return $this->hasMany(ExamCategory::class);
    }

    public function examples() {
        return $this->hasMany(ExamExampleQuestion::class);
    }
}
