<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamExampleQuestion extends Model
{
    protected $fillable = [
        'exam_id','exam_category_id','question',
        'good_answer','average_answer','bad_answer','rubric_breakdown'
    ];

    protected $casts = [
        'good_answer' => 'array',
        'average_answer' => 'array',
        'bad_answer' => 'array',
        'rubric_breakdown' => 'array',
    ];

    public function exam() {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function category() {
        return $this->belongsTo(ExamCategory::class, 'exam_category_id');
    }
}
