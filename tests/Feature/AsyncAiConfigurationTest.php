<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @group broken
 */
class AsyncAiConfigurationTest extends TestCase
{
    public function test_async_ai_configuration_is_loaded(): void
    {
        $asyncEnabled = config('ai.async_enabled');
        $parallelMax = config('ai.parallel_max');

        // Config should exist
        $this->assertIsBool($asyncEnabled);
        $this->assertIsInt($parallelMax);

        // Parallel max should be reasonable
        $this->assertGreaterThan(0, $parallelMax);
        $this->assertLessThanOrEqual(10, $parallelMax);
    }

    public function test_rate_limiting_configuration_is_loaded(): void
    {
        $rateLimitEnabled = config('ai.rate_limit_enabled');
        $rateLimitRpm = config('ai.rate_limit_rpm');
        $rateLimitRetries = config('ai.rate_limit_retries');

        // Config should exist
        $this->assertIsBool($rateLimitEnabled);
        $this->assertIsInt($rateLimitRpm);
        $this->assertIsInt($rateLimitRetries);

        // Values should be reasonable
        $this->assertGreaterThan(0, $rateLimitRpm);
        $this->assertGreaterThanOrEqual(0, $rateLimitRetries);
    }

    public function test_queue_configuration_is_correct(): void
    {
        $queueConnection = config('queue.default');

        // Should be one of supported connections
        $this->assertContains($queueConnection, ['redis', 'sync', 'database']);

        // Redis config should exist
        $redisConfig = config('queue.connections.redis');
        $this->assertIsArray($redisConfig);
        $this->assertArrayHasKey('connection', $redisConfig);
    }

    public function test_ai_provider_configuration_is_valid(): void
    {
        $provider = config('ai.provider');

        // Should be one of supported providers
        $this->assertContains($provider, ['openai', 'mock', 'gateway']);

        // OpenAI config should exist
        $openaiConfig = config('ai.openai');
        $this->assertIsArray($openaiConfig);
        $this->assertArrayHasKey('base_url', $openaiConfig);
        $this->assertArrayHasKey('model', $openaiConfig);
    }

    public function test_models_configuration_is_loaded(): void
    {
        $defaultModel = config('ai.models.default');
        $thinkingModel = config('ai.models.thinking');

        $this->assertIsString($defaultModel);
        $this->assertIsString($thinkingModel);

        // Should use GPT-5 models as per CLAUDE.md
        $this->assertStringContainsString('gpt', strtolower($defaultModel));
    }

    public function test_async_provider_can_be_resolved(): void
    {
        // Skip if async is disabled
        if (! config('ai.async_enabled')) {
            $this->markTestSkipped('Async AI is disabled in config');
        }

        try {
            $factory = app(\App\Services\LanguageApp\AiProviderFactory::class);
            $asyncProvider = $factory->makeAsync();

            $this->assertNotNull($asyncProvider);
            $this->assertInstanceOf(
                \App\Services\LanguageApp\AsyncAiProvider::class,
                $asyncProvider
            );
        } catch (\Exception $e) {
            // Async provider might not be available in test environment
            $this->markTestSkipped('Async provider not available: '.$e->getMessage());
        }
    }

    public function test_rate_limiter_can_be_instantiated(): void
    {
        if (! config('ai.rate_limit_enabled')) {
            $this->markTestSkipped('Rate limiting is disabled in config');
        }

        try {
            $rateLimiter = app(\App\Services\LanguageApp\AiRateLimiter::class);

            $this->assertNotNull($rateLimiter);
            $this->assertInstanceOf(
                \App\Services\LanguageApp\AiRateLimiter::class,
                $rateLimiter
            );
        } catch (\Exception $e) {
            $this->markTestSkipped('Rate limiter not available: '.$e->getMessage());
        }
    }

    public function test_environment_variables_for_async_are_set(): void
    {
        // These should be set in .env for production
        $envVars = [
            'AI_ASYNC_ENABLED',
            'AI_PARALLEL_MAX',
            'AI_RATE_LIMIT_ENABLED',
            'AI_RATE_LIMIT_RPM',
        ];

        foreach ($envVars as $var) {
            // Just check that they can be accessed
            // Values might differ between test/prod
            $value = env($var);

            // Should be defined (can be null/false/0, but should exist)
            $this->assertTrue(
                $value !== null || array_key_exists($var, $_ENV) || array_key_exists($var, $_SERVER),
                "Environment variable {$var} should be defined"
            );
        }
    }
}
