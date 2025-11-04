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
}
