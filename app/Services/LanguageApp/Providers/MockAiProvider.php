<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;

final class MockAiProvider implements AiProvider
{
    public function __construct(private readonly array $cfg = []) {}

    public static function clip(string $s, int $max = 300): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }

    public function generate(array $payload, array $opts = []): array
    {
        // Check if this is an identity guard request (has messages array with system/user roles)
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            return $this->generateIdentityResponse($payload, $opts);
        }

        // Original overview generation logic
        $files = is_array($opts['files'] ?? null) ? $opts['files'] : [];

        $docSources = [];
        foreach ($files as $f) {
            $docSources[] = [
                'url' => '',
                'title' => (string) ($f['name'] ?? $f['filename'] ?? $f['id'] ?? 'document'),
                'publisher' => 'user_uploaded',
                'provenance' => 'document',
                'doc_id' => (string) ($f['id'] ?? ''),
                'filename' => (string) ($f['name'] ?? ''),
            ];
        }

        $webSources = [
            [
                'url' => 'https://example.edu/exam-guide',
                'title' => 'Exam Guide',
                'publisher' => 'Example EDU',
                'provenance' => 'web',
            ],
            [
                'url' => 'https://official-exam.org/format',
                'title' => 'Official Format',
                'publisher' => 'Official Board',
                'provenance' => 'web',
            ],
        ];

        // минимальный валидный overview, который проходит JsonSchemaExamOverview
        $content = [
            'exam_name' => $payload['exam_slug'] ?? 'mock_exam',
            'exam_description' => '',
            'timebox_minutes' => 3,
            'sources' => array_merge($docSources, $webSources),
            'archetypes' => [
                [
                    'id' => 'reading_true_false',
                    'name' => 'Reading — True/False',
                    'question_types' => ['True/False/Not Given'],
                    'typical_distractors' => ['Paraphrase traps'],
                    'verbs' => ['select', 'decide'],
                    // допускаем оба формата весов — тут простой:
                    'weights' => ['Reading' => 1.0],
                    // numeric_ranges может быть объектом:
                    'numeric_ranges' => ['word_limits' => [1, 3]],
                    'difficulty' => 'medium',
                ],
            ],
            // опциональные поля можно не передавать
            'exam_matrix_provided' => false,
        ];

        $contentText = json_encode($content, JSON_UNESCAPED_UNICODE);
        $body = [
            'id' => 'mock-resp-1',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $contentText,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 50,
                'total_tokens' => 60,
            ],
        ];
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'raw' => $raw,
            'body' => $body,
            'content' => $contentText,
            'content_json' => $content,
            'usage' => $body['usage'],
        ];
    }

    /**
     * Generate mock identity guard response
     */
    private function generateIdentityResponse(array $payload, array $opts): array
    {
        // Extract user input from messages to determine exam details
        $userInput = $this->extractUserInputFromMessages($payload['messages'] ?? []);
        $examName = $userInput['exam_name'] ?? 'Mock Exam';
        $language = $userInput['language'] ?? 'English';

        // Mock identity response with high confidence for testing
        $content = [
            'status' => 'certain',
            'confidence' => 0.98,  // High confidence for testing
            'canonical' => [
                'family' => $this->guessFamily($examName),
                'name' => $examName,
                'provider' => $this->guessProvider($examName),
                'variant' => $this->guessVariant($examName),
                'language_of_test' => $language,
            ],
            'candidates' => [
                [
                    'family' => $this->guessFamily($examName),
                    'name' => $examName,
                    'provider' => $this->guessProvider($examName),
                    'score' => 0.98,
                ],
            ],
            'followups' => [],
            'need_fields' => [],
            'anchors' => [
                [
                    'page' => 1,
                    'snippet' => 'IELTS Academic exam structure',
                ],
            ],
        ];

        $contentText = json_encode($content, JSON_UNESCAPED_UNICODE);
        $body = [
            'id' => 'mock-identity-resp-1',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $contentText,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 30,
                'total_tokens' => 40,
            ],
        ];
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'raw' => $raw,
            'body' => $body,
            'content' => $content,  // Already decoded for identity responses
            'content_json' => $content,
            'usage' => $body['usage'],
        ];
    }

    /**
     * Extract user input from messages array
     */
    private function extractUserInputFromMessages(array $messages): array
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'user' && isset($msg['content'])) {
                // Try to extract JSON from message content
                if (preg_match('/```json\s*(\{.*?\})\s*```/s', $msg['content'], $matches)) {
                    $decoded = json_decode($matches[1], true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Guess exam family from name
     */
    private function guessFamily(string $examName): string
    {
        $name = strtolower($examName);

        if (str_contains($name, 'ielts')) {
            return 'IELTS';
        }
        if (str_contains($name, 'toefl')) {
            return 'TOEFL';
        }
        if (str_contains($name, 'goethe')) {
            return 'Goethe-Zertifikat';
        }
        if (str_contains($name, 'delf') || str_contains($name, 'dalf')) {
            return 'DELF/DALF';
        }
        if (str_contains($name, 'polish') || str_contains($name, 'polski')) {
            return 'Polish Language Certificate';
        }
        if (str_contains($name, 'czech') || str_contains($name, 'cce')) {
            return 'Czech Language Certificate';
        }

        return 'Mock Exam Family';
    }

    /**
     * Guess provider from exam name
     */
    private function guessProvider(string $examName): string
    {
        $name = strtolower($examName);

        if (str_contains($name, 'ielts')) {
            return 'Cambridge Assessment English / IDP / British Council';
        }
        if (str_contains($name, 'toefl')) {
            return 'ETS';
        }
        if (str_contains($name, 'goethe')) {
            return 'Goethe-Institut';
        }
        if (str_contains($name, 'delf') || str_contains($name, 'dalf')) {
            return 'France Éducation international';
        }
        if (str_contains($name, 'polish')) {
            return 'State Commission for Polish as a Foreign Language';
        }
        if (str_contains($name, 'czech') || str_contains($name, 'cce')) {
            return 'Czech Ministry of Education';
        }

        return 'Mock Provider';
    }

    /**
     * Guess variant from exam name
     */
    private function guessVariant(string $examName): ?string
    {
        $name = strtolower($examName);

        if (str_contains($name, 'academic')) {
            return 'Academic';
        }
        if (str_contains($name, 'general')) {
            return 'General Training';
        }
        if (str_contains($name, 'life skills')) {
            return 'Life Skills';
        }

        return null;
    }
}
