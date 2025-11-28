<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    protected $fillable = [
        'user_id', 'exam_id', 'exam_category_id', 'answer', 'result',
    ];

    protected $casts = [
        'result' => 'array',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Exam, covariant self>
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ExamCategory, covariant self>
     */
    public function category()
    {
        return $this->belongsTo(ExamCategory::class, 'exam_category_id');
    }
}
