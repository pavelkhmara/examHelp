<?php

namespace App\Services\LanguageApp;

use App\Services\LanguageApp\Providers\AsyncOpenAiProvider;
use App\Services\LanguageApp\Providers\MockAiProvider;
use App\Services\LanguageApp\Providers\OpenAiProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

final class AiProviderFactory
{
    public static function make(?string $provider, array $cfg): AiProvider
    {
        $cfg = $cfg ?: (array) config('ai');
        $provider = $provider ?: ($cfg['provider'] ?? 'mock');
        Log::debug('AiProviderFactory: creating provider', ['provider' => $provider]);

        if ($provider === 'mock') {
            return new MockAiProvider($cfg['mock'] ?? []);
        }

        try {
            $baseUrl = rtrim((string) ($cfg[$provider]['base_url'] ?? ''), '/').'/';
            $apiKey = (string) ($cfg[$provider]['api_key'] ?? '');
            $timeout = (int) ($cfg[$provider]['timeout'] ?? 60);

            if (! $baseUrl || ! $apiKey) {
                if (! empty($cfg['openai']['fallback_to_mock_on_error'])) {
                    Log::warning('AiProviderFactory: missing base_url/api_key -> fallback to mock');

                    return new MockAiProvider($cfg['mock'] ?? []);
                }
                throw new \RuntimeException('AI base_url/api_key is not configured.');
            }

            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => $timeout,
            ]);

            return new OpenAiProvider(
                http: $client,
                apiKey: $cfg['openai']['api_key'],
                baseUrl: $cfg['openai']['base_url'],
                model: $cfg['openai']['model'],
            );
        } catch (\Throwable $e) {
            if (! empty($cfg['openai']['fallback_to_mock_on_error'])) {
                Log::error('AiProviderFactory: provider init failed, fallback to mock', ['error' => $e->getMessage()]);

                return new MockAiProvider($cfg['mock'] ?? []);
            }
            throw new \RuntimeException("Unknown AI provider: {$provider}. Error: {$e->getMessage()}");
        }
    }

    /**
     * Create async AI provider for non-blocking requests
     */
    public static function makeAsync(?string $provider, array $cfg): AsyncAiProvider
    {
        $cfg = $cfg ?: (array) config('ai');
        $provider = $provider ?: ($cfg['provider'] ?? 'mock');
        Log::debug('AiProviderFactory: creating async provider', ['provider' => $provider]);

        if ($provider === 'mock') {
            throw new \RuntimeException('Async mock provider not implemented yet');
        }

        try {
            $baseUrl = rtrim((string) ($cfg[$provider]['base_url'] ?? ''), '/').'/';
            $apiKey = (string) ($cfg[$provider]['api_key'] ?? '');
            $timeout = (int) ($cfg[$provider]['timeout'] ?? 90);

            if (! $baseUrl || ! $apiKey) {
                throw new \RuntimeException('AI base_url/api_key is not configured.');
            }

            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout' => $timeout,
            ]);

            return new AsyncOpenAiProvider(
                http: $client,
                apiKey: $cfg['openai']['api_key'],
                baseUrl: $cfg['openai']['base_url'],
                model: $cfg['openai']['model'],
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to create async AI provider: {$provider}. Error: {$e->getMessage()}");
        }
    }
}
