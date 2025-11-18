<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Send Telegram alerts for server errors (500)
        $exceptions->report(function (Throwable $e) {
            // Only send alerts in production/staging (not local/testing)
            if (!config('monitoring.enabled') || app()->environment(['local', 'testing'])) {
                return;
            }

            // Skip if not a server error (500-level)
            if (!isServerError($e)) {
                return;
            }

            // Skip ignored exceptions
            $ignoredExceptions = config('monitoring.alerts.ignored_exceptions', []);
            foreach ($ignoredExceptions as $ignoredException) {
                if ($e instanceof $ignoredException) {
                    return;
                }
            }

            try {
                $telegramService = app(\App\Services\Monitoring\TelegramNotificationService::class);

                $requestContext = [
                    'method' => request()->method(),
                    'url' => request()->fullUrl(),
                    'ip' => request()->ip(),
                ];

                if (auth()->check()) {
                    $requestContext['user_id'] = auth()->id();
                }

                $telegramService->sendServerErrorAlert($e, $requestContext);
            } catch (\Exception $notificationError) {
                // Don't let notification failures break the application
                \Log::error('Failed to send error notification', [
                    'error' => $notificationError->getMessage(),
                ]);
            }
        });
    })->create();

/**
 * Check if exception is a server error (500-level)
 */
if (!function_exists('isServerError')) {
    function isServerError(Throwable $e): bool
    {
        // If it's an HTTP exception, check status code
        if (method_exists($e, 'getStatusCode')) {
            $code = $e->getStatusCode();
            return $code >= 500 && $code < 600;
        }

        // For non-HTTP exceptions, consider them server errors
        return true;
    }
}
