<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GenerationTask extends Model
{
    protected $fillable = ['type','status','request','response','error','attempts'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];

    public function subject(): MorphTo {
        return $this->morphTo();
    }

    public function logs() {
        return $this->hasMany(GenerationLog::class);
    }
}
