<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    protected $fillable = [
        'session_id',
        'type',
        'category',
        'category_id',
        'priority',
        'title',
        'description',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public const TYPE_WEAKNESS = 'weakness';

    public const TYPE_STRENGTH = 'strength';

    public const TYPE_SUGGESTION = 'suggestion';

    public const TYPE_RESOURCE = 'resource';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_LOW = 'low';

    /**
     * @return BelongsTo<ExamSession, covariant self>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    /**
     * @return BelongsTo<ExamCategory, covariant self>
     */
    public function examCategory(): BelongsTo
    {
        return $this->belongsTo(ExamCategory::class, 'category_id');
    }
}
