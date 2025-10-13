<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamCategory extends Model
{
    protected $fillable = ['exam_id','key','name','meta'];
    protected $casts = ['meta' => 'array'];

    public function exam() {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    public function examples() {
        return $this->hasMany(ExamExampleQuestion::class);
    }
}
