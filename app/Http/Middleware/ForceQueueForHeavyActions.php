<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceQueueForHeavyActions
{
    public function handle(Request $request, Closure $next): Response
    {
        // Блокируем попытки 'sync' выполнения тяжёлых действий в prod
        if (app()->environment('production') && $request->boolean('run_sync', false)) {
            return response()->json([
                'message' => 'Synchronous heavy execution is not allowed. Use queued tasks.',
                'code' => 'HEAVY_SYNC_BLOCKED',
            ], 400);
        }

        return $next($request);
    }
}
