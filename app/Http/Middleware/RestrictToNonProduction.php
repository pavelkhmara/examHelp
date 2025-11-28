<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictToNonProduction
{
    /**
     * Handle an incoming request.
     *
     * Restricts access to monitoring endpoints in production environment.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')) {
            abort(403, 'Monitoring endpoints are not available in production. Use local scripts instead.');
        }

        return $next($request);
    }
}
