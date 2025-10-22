<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Models\GenerationTask enqueue(string $type, ?\Illuminate\Database\Eloquent\Model $subject, array $request, string $jobClass, ?string $idempotencyKey = null, ?string $queue = null)
 */
class TaskDispatcher extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'task-dispatcher';
    }
}
