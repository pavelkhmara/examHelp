<?php

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Rate limiter for AI API requests using Redis
 *
 * Implements sliding window rate limiting to prevent API throttling
 */
class AiRateLimiter
{
    private const REDIS_KEY_PREFIX = 'ai_rate_limit:';

    public function __construct(
        private readonly int $maxRequestsPerMinute = 60,
        private readonly int $maxRetries = 3,
        private readonly int $retryDelayMs = 1000
    ) {}

    /**
     * Attempt to acquire rate limit permit
     *
     * @return bool True if permit acquired, false if rate limited
     */
    public function attempt(string $provider = 'openai'): bool
    {
        if (! $this->isEnabled()) {
            return true; // Rate limiting disabled
        }

        $key = self::REDIS_KEY_PREFIX.$provider;
        $now = microtime(true);
        $windowStart = $now - 60; // 60 seconds window

        try {
            $redis = Redis::connection();

            // Remove old entries outside the window
            $redis->zremrangebyscore($key, 0, $windowStart);

            // Count requests in current window
            $count = $redis->zcard($key);

            if ($count >= $this->maxRequestsPerMinute) {
                Log::warning('AI rate limit reached', [
                    'provider' => $provider,
                    'current' => $count,
                    'limit' => $this->maxRequestsPerMinute,
                ]);

                return false;
            }

            // Add current request to window
            $redis->zadd($key, $now, uniqid('req_', true));

            // Set expiry to clean up old keys
            $redis->expire($key, 120);

            return true;
        } catch (\Throwable $e) {
            Log::error('Rate limiter error, allowing request', ['error' => $e->getMessage()]);

            return true; // Fail open - allow request if Redis fails
        }
    }

    /**
     * Attempt to acquire permit with automatic retries
     *
     * @return bool True if permit acquired after retries
     */
    public function attemptWithRetry(string $provider = 'openai'): bool
    {
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            if ($this->attempt($provider)) {
                return true;
            }

            $attempt++;
            if ($attempt < $this->maxRetries) {
                Log::debug('Rate limit hit, retrying', [
                    'attempt' => $attempt,
                    'max' => $this->maxRetries,
                    'delay_ms' => $this->retryDelayMs,
                ]);

                usleep($this->retryDelayMs * 1000); // Convert ms to microseconds
            }
        }

        Log::error('Rate limit exceeded after all retries', [
            'provider' => $provider,
            'attempts' => $this->maxRetries,
        ]);

        return false;
    }

    /**
     * Get current request count in window
     */
    public function getCurrentCount(string $provider = 'openai'): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        try {
            $key = self::REDIS_KEY_PREFIX.$provider;
            $now = microtime(true);
            $windowStart = $now - 60;

            $redis = Redis::connection();
            $redis->zremrangebyscore($key, 0, $windowStart);

            return $redis->zcard($key);
        } catch (\Throwable $e) {
            Log::error('Failed to get rate limit count', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Get time until rate limit resets (seconds)
     */
    public function getTimeUntilReset(string $provider = 'openai'): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        try {
            $key = self::REDIS_KEY_PREFIX.$provider;
            $redis = Redis::connection();

            // Get oldest request timestamp
            $oldest = $redis->zrange($key, 0, 0, 'WITHSCORES');

            if (empty($oldest)) {
                return 0;
            }

            $oldestTimestamp = array_values($oldest)[0];
            $resetTime = $oldestTimestamp + 60 - microtime(true);

            return max(0, (int) ceil($resetTime));
        } catch (\Throwable $e) {
            Log::error('Failed to get reset time', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Check if rate limiting is enabled
     */
    private function isEnabled(): bool
    {
        return config('ai.rate_limit_enabled', false) && $this->maxRequestsPerMinute > 0;
    }

    /**
     * Clear rate limit for provider (for testing)
     */
    public function clear(string $provider = 'openai'): void
    {
        try {
            $key = self::REDIS_KEY_PREFIX.$provider;
            Redis::connection()->del($key);

            Log::debug('Rate limit cleared', ['provider' => $provider]);
        } catch (\Throwable $e) {
            Log::error('Failed to clear rate limit', ['error' => $e->getMessage()]);
        }
    }
}
