<?php

namespace App\Services\LanguageApp;

interface AiProvider
{
    /**
     * @param array $payload
     * @param array $opts     ['schema'=>array|null,'web'=>bool,'files'=>array<int,\SplFileInfo|string>]
     * @return array          ['ok'=>bool,'data'=>mixed,'usage'=>array,'raw'=>mixed]
     */
    public function generate(array $payload, array $opts = []): array;
}
