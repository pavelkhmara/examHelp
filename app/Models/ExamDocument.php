<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamDocument extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'exam_documents';

    protected $fillable = [
        'id',
        'exam_id',
        'generation_task_id',
        'original_name',
        'disk',
        'path',
        'mime',
        'size',
        'status',
        'extracted_text',
        'error',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function generationTask(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\GenerationTask::class, 'generation_task_id');
    }
}
