<?php

namespace App\Services\LanguageApp;

use App\Models\GenerationLog;
use App\Models\GenerationTask;

abstract class AbstractAiService
{
    protected function callAi(array $payload): array
    {
        if (env('AI_ENABLE_MOCK')) {
            $mock = [
                'sections' => [
                    ['key'=>'listening','title'=>'Listening','count'=>20,'time_per_question_sec'=>30,'prep_time_sec'=>0,'notes'=>'MCQ'],
                    ['key'=>'reading','title'=>'Reading','count'=>20,'time_per_question_sec'=>45,'prep_time_sec'=>0,'notes'=>'MCQ + True/False'],
                    ['key'=>'speaking','title'=>'Speaking','count'=>4,'time_per_question_sec'=>90,'prep_time_sec'=>30,'notes'=>'monologue/dialogue'],
                    ['key'=>'writing','title'=>'Writing','count'=>2,'time_per_question_sec'=>1200,'prep_time_sec'=>0,'notes'=>'Essay + Letter'],
                ],
                'total_score' => ['min'=>0,'max'=>100],
            ];
            return ['ok'=>true,'data'=>$mock,'usage'=>['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],'raw'=>['mock'=>true]];
        }

        // ↓ дальше оставь текущую логику реального вызова
        static $runner = null;
        if ($runner === null) {
            $runner = \App\Services\LanguageApp\AiProviderFactory::make();
        }

        $messages = [
            ['role' => 'system', 'content' => 'You extract exam structure. Output JSON only.'],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ];

        $schema = json_encode([
            'type' => 'object',
            'required' => ['sections', 'total_score'],
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['key','title','count'],
                        'properties' => [
                            'key' => ['type'=>'string'],
                            'title' => ['type'=>'string'],
                            'count' => ['type'=>'integer'],
                            'time_per_question_sec' => ['type'=>'integer'],
                            'prep_time_sec' => ['type'=>'integer'],
                            'notes' => ['type'=>'string'],
                        ],
                    ],
                ],
                'total_score' => [
                    'type'=>'object',
                    'required'=>['min','max'],
                    'properties'=>[
                        'min'=>['type'=>'integer'],
                        'max'=>['type'=>'integer'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            return $runner($messages, $schema);
        } catch (\Throwable $e) {
            if (env('AI_FALLBACK_TO_MOCK_ON_ERROR', true)) {
                $mock = [
                    'sections' => [
                        ['key'=>'listening','title'=>'Listening','count'=>20,'time_per_question_sec'=>30,'prep_time_sec'=>0,'notes'=>'MCQ'],
                        ['key'=>'reading','title'=>'Reading','count'=>20,'time_per_question_sec'=>45,'prep_time_sec'=>0,'notes'=>'MCQ + True/False'],
                        ['key'=>'speaking','title'=>'Speaking','count'=>4,'time_per_question_sec'=>90,'prep_time_sec'=>30,'notes'=>'monologue/dialogue'],
                        ['key'=>'writing','title'=>'Writing','count'=>2,'time_per_question_sec'=>1200,'prep_time_sec'=>0,'notes'=>'Essay + Letter'],
                    ],
                    'total_score' => ['min'=>0,'max'=>100],
                ];
                return ['ok'=>true,'data'=>$mock,'usage'=>['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],'raw'=>['fallback'=>'mock','error'=>$e->getMessage()]];
            }
            throw $e;
        }
    }



    // protected function callAi(array $payload): array
    // {
    //     static $runner = null;

    //     if ($runner === null) {
    //         $runner = \App\Services\LanguageApp\AiProviderFactory::make();
    //     }

    //     $messages = [
    //         ['role' => 'system', 'content' => 'You extract exam structure. Output JSON only.'],
    //         ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
    //     ];

    //     $schema = json_encode([
    //         'type' => 'object',
    //         'required' => ['sections', 'total_score'],
    //         'properties' => [
    //             'sections' => [
    //                 'type' => 'array',
    //                 'items' => [
    //                     'type' => 'object',
    //                     'required' => ['key', 'title', 'count'],
    //                     'properties' => [
    //                         'key' => ['type' => 'string'],
    //                         'title' => ['type' => 'string'],
    //                         'count' => ['type' => 'integer'],
    //                         'time_per_question_sec' => ['type' => 'integer'],
    //                         'prep_time_sec'        => ['type' => 'integer'],
    //                         'notes'                => ['type' => 'string'],
    //                     ],
    //                 ],
    //             ],
    //             'total_score' => [
    //                 'type' => 'object',
    //                 'required' => ['min', 'max'],
    //                 'properties' => [
    //                     'min' => ['type' => 'integer'],
    //                     'max' => ['type' => 'integer'],
    //                 ],
    //             ],
    //         ],
    //     ], JSON_UNESCAPED_UNICODE);

    //     $res = $runner($messages, $schema);

    //     // Нормализуем возвращаемое значение, чтобы потребители не падали на null
    //     if (!isset($res['data']) || !is_array($res['data'])) {
    //         throw new \RuntimeException('AI runner returned empty data');
    //     }

    //     return $res;
    // }



    protected function log(GenerationTask $task, string $stage, array $request, array $response): void
    {
        GenerationLog::create([
            'generation_task_id' => $task->id,
            'stage' => $stage,
            'request' => $request,
            'response' => $response['data'] ?? null,
            'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $response['usage']['total_tokens'] ?? 0,
        ]);
    }
}
