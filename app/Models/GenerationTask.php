<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationTask extends Model
{
    protected $fillable = ['type','status','request','response','error','attempts', 'result'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'result'   => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): MorphTo {
        return $this->morphTo();
    }

    public function logs() {
        return $this->hasMany(GenerationLog::class);
    }
}
