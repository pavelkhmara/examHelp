<?php

namespace App\Services\LanguageApp;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Async AI Provider interface for non-blocking AI requests
 */
interface AsyncAiProvider
{
    /**
     * Generate AI response asynchronously
     *
     * @param  array  $payload  Request payload (messages, input, etc.)
     * @param  array  $opts  Options ['schema'=>array|null,'web'=>bool,'files'=>array,'model'=>string|null]
     * @return PromiseInterface Promise that resolves to ['ok'=>bool,'data'=>mixed,'usage'=>array,'raw'=>mixed]
     */
    public function generateAsync(array $payload, array $opts = []): PromiseInterface;

    /**
     * Generate multiple AI responses in parallel
     *
     * @param  array  $requests  Array of ['payload'=>array, 'opts'=>array, 'key'=>string] requests
     * @return PromiseInterface Promise that resolves to array of keyed results
     */
    public function generateBatch(array $requests): PromiseInterface;
}
