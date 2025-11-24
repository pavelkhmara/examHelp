<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trait EnsuresConnectionStability
 *
 * Provides connection stability helpers for long-running jobs.
 * Use this trait in jobs that may run for extended periods (>2 minutes).
 *
 * Features:
 * - Automatic connection reconnection before critical operations
 * - Heartbeat mechanism for connection keep-alive
 * - Graceful handling of connection loss
 *
 * Usage:
 * ```php
 * class MyLongRunningJob implements ShouldQueue
 * {
 *     use EnsuresConnectionStability;
 *
 *     public function handle(): void
 *     {
 *         $this->ensureConnection();
 *         // ... your code
 *         $this->keepConnectionAlive();
 *         // ... more code
 *     }
 * }
 * ```
 */
trait EnsuresConnectionStability
{
    /**
     * Ensure database connection is active.
     * Reconnects if connection is lost or stale.
     *
     * @param string $connection Connection name (default: null = default connection)
     * @return void
     */
    protected function ensureConnection(?string $connection = null): void
    {
        try {
            // Try a simple query to check connection
            DB::connection($connection)->getPdo();
        } catch (\Throwable $e) {
            Log::warning('Database connection lost, reconnecting', [
                'job' => static::class,
                'error' => $e->getMessage(),
            ]);

            // Reconnect
            DB::connection($connection)->reconnect();

            Log::info('Database connection restored', [
                'job' => static::class,
            ]);
        }
    }

    /**
     * Keep connection alive by sending a ping query.
     * Call this periodically in long-running operations (every 5-10 minutes).
     *
     * @param string $connection Connection name (default: null = default connection)
     * @return void
     */
    protected function keepConnectionAlive(?string $connection = null): void
    {
        try {
            DB::connection($connection)->select('SELECT 1');
        } catch (\Throwable $e) {
            Log::warning('Connection keep-alive failed, reconnecting', [
                'job' => static::class,
                'error' => $e->getMessage(),
            ]);

            DB::connection($connection)->reconnect();
        }
    }

    /**
     * Execute a database operation with automatic retry on connection failure.
     *
     * @param callable $callback The operation to execute
     * @param int $maxRetries Maximum number of retries (default: 3)
     * @param int $retryDelay Delay between retries in milliseconds (default: 1000)
     * @param string|null $connection Connection name
     * @return mixed The result of the callback
     * @throws \Throwable If all retries fail
     */
    protected function retryOnConnectionFailure(
        callable $callback,
        int $maxRetries = 3,
        int $retryDelay = 1000,
        ?string $connection = null
    ): mixed {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                // Ensure connection is alive before attempting
                $this->ensureConnection($connection);

                // Execute the callback
                return $callback();
            } catch (\Illuminate\Database\QueryException $e) {
                $lastException = $e;
                $attempt++;

                // Check if this is a connection-related error
                $isConnectionError = $this->isConnectionError($e);

                if (!$isConnectionError || $attempt >= $maxRetries) {
                    throw $e;
                }

                Log::warning('Database operation failed, retrying', [
                    'job' => static::class,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                // Wait before retrying
                usleep($retryDelay * 1000);

                // Force reconnection
                DB::connection($connection)->reconnect();
            }
        }

        throw $lastException ?? new \RuntimeException('Unknown error in retryOnConnectionFailure');
    }

    /**
     * Check if an exception is related to connection issues.
     *
     * @param \Throwable $e
     * @return bool
     */
    protected function isConnectionError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $connectionErrors = [
            'connection refused',
            'gone away',
            'lost connection',
            'connection reset',
            'broken pipe',
            'deadlock',
            'lock wait timeout',
            'connection timed out',
            'no connection',
        ];

        foreach ($connectionErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }

        return false;
    }
}
