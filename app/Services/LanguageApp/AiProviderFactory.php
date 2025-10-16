<?php

namespace App\Services\LanguageApp;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use App\Services\LanguageApp\Providers\OpenAiProvider;
use App\Services\LanguageApp\Providers\MockAiProvider;

final class AiProviderFactory
{
    public static function make(?string $provider, array $cfg): AiProvider
    {
        $provider = $provider ?: 'mock';
        Log::debug('AiProviderFactory: creating provider', [ 'provider' => $provider ]);

        if ($provider === 'mock') {
            return new MockAiProvider($cfg['mock'] ?? []);
        }

        try {
            $baseUrl = rtrim($cfg[$provider]['base_url'], '/').'/';
            $apiKey  = $cfg[$provider]['api_key'];
            $timeout = (int)($cfg[$provider]['timeout'] ?? 60);
    
            if (!$baseUrl || !$apiKey) {
                throw new \RuntimeException('AI base_url/api_key is not configured.');
            }
    
            $client = new Client([
                'base_uri' => $baseUrl,
                'timeout'  => $timeout,
            ]);
    
            
            return new OpenAiProvider(
                http:   $client,
                apiKey: $cfg['openai']['api_key'],
                baseUrl:$cfg['openai']['base_url'],
                model:  $cfg['openai']['model'],
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unknown AI provider: {$provider}. Error: {$e->getMessage()}");
        }
    }
}
