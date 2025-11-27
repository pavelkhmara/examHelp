<?php

namespace App\Services\LanguageApp\Providers;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\AsyncAiProvider;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Create;

final class MockAiProvider implements AiProvider, AsyncAiProvider
{
    public function __construct(private readonly array $cfg = []) {}

    public static function clip(string $s, int $max = 300): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }

    public function generate(array $payload, array $opts = []): array
    {
        // Check if this is a question synthesis request
        $promptClass = $opts['prompt_class'] ?? null;
        if ($promptClass === 'App\\Services\\LanguageApp\\Prompts\\PromptQuestionSynthesis') {
            return $this->generateQuestionSynthesisResponse($payload, $opts);
        }

        // Check if this is an identity guard request (has messages array with system/user roles)
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            return $this->generateIdentityResponse($payload, $opts);
        }

        // Check for v2 architecture stages
        $stage = $payload['stage'] ?? null;

        if ($stage === 'phase_a_skeleton') {
            return $this->generatePhaseAResponse($payload, $opts);
        }

        if ($stage === 'phase_b_assembly' || $stage === 'section_assembly') {
            return $this->generatePhaseBOrSectionResponse($payload, $opts);
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
            'model' => 'mock_model',
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => $payload['messages'] ?? [],  // For logging
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
            'model' => 'mock_model',
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => $payload['messages'] ?? [],  // For logging
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

    /**
     * Generate mock Phase A response (skeleton structure)
     */
    private function generatePhaseAResponse(array $payload, array $opts): array
    {
        $examTitle = $payload['exam_title'] ?? 'Mock Exam';

        // Mock skeleton with 3 sections (listening, reading, writing)
        $content = [
            'canonical' => [
                'family' => $this->guessFamily($examTitle),
                'name' => $examTitle,
                'provider' => $this->guessProvider($examTitle),
                'language_of_test' => 'English',
            ],
            'administration' => [
                'total_time_minutes' => 150,
                'break_time_minutes' => 10,
            ],
            'sections' => [
                [
                    'id' => 'listening',
                    'title' => 'Listening',
                    'skill' => 'listening',
                    'duration_min' => 30,
                    'max_score' => 40,
                    'min_pass_percent' => null,
                    'tasks' => [
                        [
                            'id' => 'task_1',
                            'title' => 'Task 1',
                            'description' => 'Listen to a conversation',
                        ],
                    ],
                ],
                [
                    'id' => 'reading',
                    'title' => 'Reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'min_pass_percent' => null,
                    'tasks' => [
                        [
                            'id' => 'task_1',
                            'title' => 'Task 1',
                            'description' => 'Read a passage',
                        ],
                    ],
                ],
                [
                    'id' => 'writing',
                    'title' => 'Writing',
                    'skill' => 'writing',
                    'duration_min' => 60,
                    'max_score' => 20,
                    'min_pass_percent' => null,
                    'tasks' => [
                        [
                            'id' => 'task_1',
                            'title' => 'Task 1',
                            'description' => 'Write an essay',
                        ],
                    ],
                ],
            ],
        ];

        $contentText = json_encode($content, JSON_UNESCAPED_UNICODE);
        $body = [
            'id' => 'mock-phase-a-resp',
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
                'prompt_tokens' => 20,
                'completion_tokens' => 100,
                'total_tokens' => 120,
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
            'model' => 'mock_model',
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => [],
        ];
    }

    /**
     * Generate mock Phase B or Section Assembly response
     */
    private function generatePhaseBOrSectionResponse(array $payload, array $opts): array
    {
        $stage = $payload['stage'] ?? 'section_assembly';
        $isSection = $stage === 'section_assembly';

        if ($isSection) {
            // Single section assembly
            $sectionSkeleton = $payload['section_skeleton'] ?? [];
            $sectionId = $sectionSkeleton['id'] ?? 'unknown';
            $sectionTitle = $sectionSkeleton['title'] ?? 'Unknown Section';
            $skill = $sectionSkeleton['skill'] ?? 'listening';

            $content = [
                'id' => $sectionId,
                'title' => $sectionTitle,
                'skill' => $skill,
                'duration_min' => $sectionSkeleton['duration_min'] ?? 30,
                'max_score' => $sectionSkeleton['max_score'] ?? 40,
                'tasks' => $sectionSkeleton['tasks'] ?? [],
                'question_archetypes' => [
                    [
                        'id' => "{$sectionId}_mcq_01",
                        'type' => $skill === 'listening' ? 'listen_mcq' : 'read_mcq',
                        'name' => ucfirst($skill).' MCQ',
                        'difficulty' => 'medium',
                        'config' => [
                            'options_count' => 4,
                            'duration_sec' => 120,
                            'scoring' => [
                                'max_points' => 1,
                                'partial_credit' => false,
                            ],
                        ],
                    ],
                ],
                'assembly' => $this->generateMockAssembly($skill, $sectionId),
            ];
        } else {
            // Full Phase B - all sections with assembly
            $phaseASkeleton = $payload['phase_a_skeleton'] ?? [];
            $sections = [];

            foreach ($phaseASkeleton['sections'] ?? [] as $section) {
                $sectionId = $section['id'] ?? 'unknown';
                $skill = $section['skill'] ?? 'listening';

                $sections[] = [
                    'id' => $sectionId,
                    'title' => $section['title'] ?? 'Unknown',
                    'skill' => $skill,
                    'duration_min' => $section['duration_min'] ?? 30,
                    'max_score' => $section['max_score'] ?? 40,
                    'tasks' => $section['questions'] ?? [],
                    'question_archetypes' => [
                        [
                            'id' => "{$sectionId}_mcq_01",
                            'type' => $skill === 'listening' ? 'listen_mcq' : 'read_mcq',
                            'name' => ucfirst($skill).' MCQ',
                            'difficulty' => 'medium',
                            'config' => [
                                'options_count' => 4,
                                'duration_sec' => 120,
                                'scoring' => [
                                    'max_points' => 1,
                                    'partial_credit' => false,
                                ],
                            ],
                        ],
                    ],
                    'assembly' => [
                        'mode' => 'pool',
                        'filters' => [
                            [
                                'type' => $skill === 'listening' ? 'listen_mcq' : 'read_mcq',
                                'difficulty' => ['easy', 'medium'],
                                'pick' => 10,
                            ],
                        ],
                        'assertions' => [
                            'total_questions_equals' => 10,
                            'max_points_sum_equals' => 10,
                        ],
                    ],
                ];
            }

            $content = [
                'canonical' => $phaseASkeleton['canonical'] ?? [],
                'administration' => $phaseASkeleton['administration'] ?? [],
                'sections' => $sections,
            ];
        }

        $contentText = json_encode($content, JSON_UNESCAPED_UNICODE);
        $body = [
            'id' => $isSection ? 'mock-section-resp' : 'mock-phase-b-resp',
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
                'prompt_tokens' => 30,
                'completion_tokens' => 150,
                'total_tokens' => 180,
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
            'model' => 'mock_model',
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => [],
        ];
    }

    /**
     * Generate appropriate assembly configuration based on skill type
     */
    private function generateMockAssembly(string $skill, string $sectionId): array
    {
        // Listening and Reading sections use pool mode (filters)
        if (in_array($skill, ['listening', 'reading'])) {
            return [
                'mode' => 'pool',
                'filters' => [
                    [
                        'type' => $skill === 'listening' ? 'listen_mcq' : 'read_mcq',
                        'difficulty' => ['easy', 'medium'],
                        'pick' => 10,
                    ],
                ],
                'assertions' => [
                    'total_questions_equals' => 10,
                    'max_points_sum_equals' => 10,
                ],
            ];
        }

        // Writing and Speaking sections use blueprint mode with slots
        if (in_array($skill, ['writing', 'speaking'])) {
            return [
                'mode' => 'blueprint',
                'slots' => [  // Use 'slots' like real AI provider (AssemblyResolver supports both)
                    [
                        'slot_id' => "{$sectionId}_task_1",
                        'type' => $skill === 'writing' ? 'writing_prompt' : 'speaking_prompt',
                        'difficulty' => 'medium',
                        'pick' => 1,
                        'tags' => [],
                    ],
                ],
                'assertions' => [
                    'total_questions_equals' => 1,
                    'max_points_sum_equals' => 10,
                ],
            ];
        }

        // Fallback to inline mode with empty questions
        return [
            'mode' => 'inline',
            'questions' => [],
        ];
    }

    /**
     * Generate mock question synthesis response
     */
    private function generateQuestionSynthesisResponse(array $payload, array $opts): array
    {
        // Extract question type and quantity from prompt args
        // prompt_args = [type, config, skill, language, level, quantity, ...]
        $promptArgs = $opts['prompt_args'] ?? [];
        $questionType = $promptArgs[0] ?? 'single_select';
        $quantity = $promptArgs[5] ?? 1;  // ✅ FIX: quantity is at index 5, not 4 (4 = level)

        // ✅ NEW: Use question metadata from opts if available (for question_groups)
        $questionsByGroup = $opts['_questions_by_group'] ?? [];

        // DEBUG: Check what IDs we're receiving
        if (!empty($questionsByGroup)) {
            \Log::debug('[MockAiProvider] Received _questions_by_group', [
                'count' => count($questionsByGroup),
                'first_question_id' => $questionsByGroup[0]['question_id'] ?? 'N/A',
                'all_ids' => array_map(fn($q) => $q['question_id'] ?? 'N/A', $questionsByGroup),
            ]);
        }

        // Generate mock questions
        $questions = [];
        for ($i = 0; $i < $quantity; $i++) {
            // ✅ Use metadata from _questions_by_group if available
            $questionMeta = $questionsByGroup[$i] ?? null;
            $qType = $questionMeta['type'] ?? $questionType;
            $qId = $questionMeta['question_id'] ?? "q{$i}";

            // Generate question based on type
            $questions[] = $this->generateMockQuestionByType($qId, $qType, $questionMeta);
        }

        $contentText = json_encode($questions, JSON_UNESCAPED_UNICODE);
        $body = [
            'id' => 'mock-question-synthesis-resp',
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
            'content' => $questions,  // ✅ FIX: Return array for ParallelQuestionSynthesizer
            'content_text' => $contentText,
            'content_json' => $questions,
            'usage' => $body['usage'],
            'model' => 'mock_model',
            'model_alias' => $opts['model'] ?? null,
            'sent_messages' => $payload['messages'] ?? [],
        ];
    }

    /**
     * Generate mock question based on type
     *
     * @param string $questionId Question ID
     * @param string $type Question type
     * @param array|null $meta Optional metadata
     * @return array Question data
     */
    private function generateMockQuestionByType(string $questionId, string $type, ?array $meta = null): array
    {
        // Base question structure
        $question = [
            'id' => $questionId,
            'type' => $type,
            'skills_measured' => ['comprehension'],
            'time_limit_sec' => 60,
            'instructions' => [
                'brief' => 'Answer the question',  // ✅ Required by JsonSchemaQuestionV2
                'full' => 'Read the question carefully and select the correct answer.',  // ✅ Required
            ],
            'stimulus' => [],
            'response' => [],
            'scoring' => [
                'max_score' => 1,
                'method' => 'keyed',  // ✅ Valid method from JsonSchemaQuestionV2
                'answer_key' => [],  // ✅ Required for keyed/partial/hybrid methods (will be filled per type)
            ],
            'metadata' => [
                'cefr' => ['B1'],  // ✅ Required: non-empty array of valid CEFR levels
                'difficulty' => 'medium',  // ✅ Required: easy, medium, or hard
                'topic' => 'General',  // ✅ Required: string
                'language' => 'en',  // ✅ Required: string
            ],
        ];

        // Generate type-specific interaction
        switch ($type) {
            case 'listen_mcq':
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'Listen to the audio and choose the correct answer.',
                    'options' => [
                        ['id' => 'A', 'text' => 'Option A - First choice'],  // ✅ 'id' not 'key'
                        ['id' => 'B', 'text' => 'Option B - Second choice'],
                        ['id' => 'C', 'text' => 'Option C - Third choice'],
                        ['id' => 'D', 'text' => 'Option D - Fourth choice'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['A'],
                ];
                $question['scoring']['answer_key'] = ['A'];  // ✅ Required for keyed method
                $question['stimulus'] = ['text_html' => '<p>Mock audio transcript: Speaker discusses topic.</p>'];
                break;

            case 'listen_true_false':
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'Listen to the statement and determine if it is true or false.',
                    'options' => [
                        ['id' => 'True', 'text' => 'True'],  // ✅ 'id' not 'key'
                        ['id' => 'False', 'text' => 'False'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['True'],
                ];
                $question['scoring']['answer_key'] = ['True'];  // ✅ Required for keyed method
                $question['stimulus'] = ['text_html' => '<p>Mock audio transcript: Statement about topic.</p>'];
                break;

            case 'listen_yes_no_ng':
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'Listen and answer: Yes, No, or Not Given.',
                    'options' => [
                        ['id' => 'Yes', 'text' => 'Yes'],  // ✅ 'id' not 'key'
                        ['id' => 'No', 'text' => 'No'],
                        ['id' => 'NG', 'text' => 'Not Given'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['Yes'],
                ];
                $question['scoring']['answer_key'] = ['Yes'];  // ✅ Required for keyed method
                $question['stimulus'] = ['text_html' => '<p>Mock audio transcript: Information presented.</p>'];
                break;

            case 'single_select':
            case 'multi_select':
                $answer = $type === 'multi_select' ? ['A', 'B'] : ['A'];
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'Mock question stem for testing',
                    'options' => [
                        ['id' => 'A', 'text' => 'Option A'],  // ✅ 'id' not 'key'
                        ['id' => 'B', 'text' => 'Option B'],
                        ['id' => 'C', 'text' => 'Option C'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => $answer,
                ];
                $question['scoring']['answer_key'] = $answer;  // ✅ Required for keyed method
                break;

            case 'true_false':
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'This is a true/false statement.',
                    'options' => [
                        ['id' => 'True', 'text' => 'True'],  // ✅ 'id' not 'key'
                        ['id' => 'False', 'text' => 'False'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['True'],
                ];
                $question['scoring']['answer_key'] = ['True'];  // ✅ Required for keyed method
                break;

            case 'short_answer':
            case 'dictation':
                $question['interaction'] = [
                    'response_type' => 'text',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => 'Type your answer below.',
                    'input_type' => 'text',
                ];
                $question['response'] = [
                    'mode' => 'text',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['sample answer'],
                ];
                $question['scoring']['method'] = 'partial';  // ✅ Valid method for fuzzy matching
                $question['scoring']['answer_key'] = ['sample answer'];  // ✅ Required for partial method
                if ($type === 'dictation') {
                    $question['stimulus'] = ['text_html' => '<p>Mock audio: Speaker says sentence.</p>'];
                }
                break;

            default:
                // Default: single_select structure
                $question['interaction'] = [
                    'response_type' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'stem' => "Mock question for type {$type}",
                    'options' => [
                        ['id' => 'A', 'text' => 'Option A'],  // ✅ 'id' not 'key'
                        ['id' => 'B', 'text' => 'Option B'],
                    ],
                ];
                $question['response'] = [
                    'mode' => 'selection',  // ✅ Required by JsonSchemaQuestionV2
                    'correct' => ['A'],
                ];
                $question['scoring']['answer_key'] = ['A'];  // ✅ Required for keyed method
                break;
        }

        return $question;
    }

    /**
     * Async mock - just wraps sync generate() in resolved promise
     */
    public function generateAsync(array $payload, array $opts = []): PromiseInterface
    {
        $result = $this->generate($payload, $opts);
        return Create::promiseFor($result);
    }

    /**
     * Batch async mock - calls generateAsync() for each request and resolves all
     */
    public function generateBatch(array $requests): PromiseInterface
    {
        $results = [];
        foreach ($requests as $request) {
            $key = $request['key'] ?? count($results);
            $payload = $request['payload'] ?? [];
            $opts = $request['opts'] ?? [];
            $results[$key] = $this->generate($payload, $opts);
        }
        return Create::promiseFor($results);
    }
}
