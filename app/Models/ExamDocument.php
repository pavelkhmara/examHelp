<?php

namespace App\Models;

use App\Casts\AsArrayWithUnescapedSlashes;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $exam_id
 * @property int|null $generation_task_id
 * @property string|null $original_name
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $status
 * @property string|null $extracted_text
 * @property string|null $openai_file_id
 * @property string|null $file_attachment_status
 * @property \Carbon\Carbon|null $file_attached_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * Virtual properties:
 * @property string|null $filename Alias for original_name
 */
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
        'openai_file_id',
        'file_attachment_status',
        'file_attached_at',
        'error',
        'meta',
    ];

    protected $casts = [
        'meta' => AsArrayWithUnescapedSlashes::class,
        'file_attached_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Exam>
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<GenerationTask>
     */
    public function generationTask(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\GenerationTask::class, 'generation_task_id');
    }

    /**
     * Get filename (alias for original_name)
     */
    public function getFilenameAttribute(): ?string
    {
        return $this->original_name;
    }
}
