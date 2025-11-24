<?php

namespace Tests\Helpers;

use App\Models\Exam;
use PHPUnit\Framework\Assert;

/**
 * Helper для создания exam fixtures в тестах
 */
class ExamTestHelper
{
    /**
     * Создать exam со всеми обязательными полями (QuickCheck ready)
     *
     * @param array $overrides
     * @param bool $withEvents Если false, создает exam без триггеринга events/observers (default: false)
     */
    public static function createExamWithAllFields(array $overrides = [], bool $withEvents = false): Exam
    {
        $factory = Exam::factory();

        $data = array_merge([
            'title' => 'IELTS Academic',
            'language_of_test' => 'en',
            'level' => 'B2',
            'user_input' => 'IELTS Academic test for university admission',
            'description' => 'English proficiency test for academic purposes',
            'identity' => [
                'canonical' => [
                    'family' => 'IELTS',
                    'provider' => 'IDP',
                    'exam_name' => 'IELTS Academic',
                    'level' => 'B2',
                ],
                'exam_language' => 'en',
            ],
        ], $overrides);

        if ($withEvents) {
            return $factory->create($data);
        }

        // Создать без событий (не триггерит ExamObserver)
        return Exam::withoutEvents(fn() => $factory->create($data));
    }

    /**
     * Создать exam с минимальными данными (только title)
     *
     * @param array $overrides
     * @param bool $withEvents Если false, создает exam без триггеринга events/observers (default: false)
     */
    public static function createExamWithMinimalData(array $overrides = [], bool $withEvents = false): Exam
    {
        $factory = Exam::factory();

        $data = array_merge([
            'title' => 'Test Exam',
            'slug' => 'test-exam-' . uniqid(),
            // Остальные поля NULL или default
        ], $overrides);

        if ($withEvents) {
            return $factory->create($data);
        }

        // Создать без событий (не триггерит ExamObserver)
        return Exam::withoutEvents(fn() => $factory->create($data));
    }

    /**
     * Создать exam с Phase A структурой (pass_policy + sections)
     */
    public static function createExamWithPhaseA(array $overrides = []): Exam
    {
        $exam = self::createExamWithAllFields($overrides);

        $phaseAStructure = self::getValidPhaseAStructure();

        $exam->update([
            'meta' => array_merge($exam->meta ?? [], [
                'structure_v2' => $phaseAStructure,
            ]),
            'research_status' => 'completed',
        ]);

        return $exam->fresh();
    }

    /**
     * Создать exam с Phase B структурой (Phase A + assembly configs)
     */
    public static function createExamWithPhaseB(array $overrides = []): Exam
    {
        $exam = self::createExamWithPhaseA($overrides);

        $phaseBData = self::getValidPhaseBStructure();

        // Merge assembly configs в существующие секции
        $structure = $exam->meta['structure_v2'];
        foreach ($phaseBData['sections'] as $phaseBSection) {
            $sectionId = $phaseBSection['id'];
            foreach ($structure['sections'] as &$section) {
                if ($section['id'] === $sectionId) {
                    $section['assembly'] = $phaseBSection['assembly'];
                }
            }
        }

        $exam->update([
            'meta' => array_merge($exam->meta, [
                'structure_v2' => $structure,
            ]),
        ]);

        return $exam->fresh();
    }

    /**
     * Получить валидную Phase A структуру
     */
    public static function getValidPhaseAStructure(): array
    {
        return [
            'id' => 'ielts-academic-test',
            'version' => '2.0',
            'meta' => [
                'name' => 'IELTS Academic',
                'language' => 'en',
                'level' => 'B2',
                'provider' => 'IDP/British Council',
            ],
            'pass_policy' => [
                'mode' => 'mixed',
                'overall_threshold_percent' => 60,
                'per_section_threshold_percent' => 50,
            ],
            'policies' => [],
            'sections' => [
                [
                    'id' => 'sec-listening',
                    'skill' => 'listening',
                    'duration_min' => 30,
                    'max_score' => 40,
                    'min_pass_percent' => 50,
                ],
                [
                    'id' => 'sec-reading',
                    'skill' => 'reading',
                    'duration_min' => 60,
                    'max_score' => 40,
                    'min_pass_percent' => 50,
                ],
            ],
        ];
    }

    /**
     * Получить валидную Phase B структуру
     */
    public static function getValidPhaseBStructure(): array
    {
        return [
            'sections' => [
                [
                    'id' => 'sec-listening',
                    'assembly' => [
                        'mode' => 'blueprint',
                        'blueprint' => [
                            [
                                'slot' => 'slot-1',
                                'from_pool' => 'listening-pool',
                                'pick' => 10,
                                'filters' => [
                                    'type' => ['single_select'],
                                ],
                            ],
                        ],
                        'assertions' => [
                            'total_questions_equals' => 10,
                        ],
                    ],
                ],
                [
                    'id' => 'sec-reading',
                    'assembly' => [
                        'mode' => 'pool',
                        'pool_id' => 'reading-pool',
                        'pick' => 40,
                        'seed' => 'test-seed',
                        'filters' => [
                            'type' => ['single_select', 'true_false'],
                        ],
                        'assertions' => [
                            'total_questions_equals' => 40,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Assert что Phase A структура валидна
     */
    public static function assertValidPhaseA(array $structure): void
    {
        Assert::assertArrayHasKey('pass_policy', $structure);
        Assert::assertArrayHasKey('sections', $structure);
        Assert::assertArrayHasKey('meta', $structure);

        $policy = $structure['pass_policy'];
        $mode = $policy['mode'];

        Assert::assertContains($mode, ['overall_only', 'per_section_only', 'mixed']);

        switch ($mode) {
            case 'overall_only':
                Assert::assertArrayHasKey('overall_threshold_percent', $policy);
                break;
            case 'per_section_only':
                Assert::assertArrayHasKey('per_section_threshold_percent', $policy);
                break;
            case 'mixed':
                Assert::assertArrayHasKey('overall_threshold_percent', $policy);
                Assert::assertArrayHasKey('per_section_threshold_percent', $policy);
                break;
        }

        Assert::assertIsArray($structure['sections']);
        Assert::assertNotEmpty($structure['sections']);
    }

    /**
     * Assert что Phase B assembly config валиден
     */
    public static function assertValidPhaseB(array $structure): void
    {
        self::assertValidPhaseA($structure); // Phase B includes Phase A

        foreach ($structure['sections'] as $section) {
            Assert::assertArrayHasKey('assembly', $section, "Section {$section['id']} missing assembly config");

            $assembly = $section['assembly'];
            Assert::assertArrayHasKey('mode', $assembly);
            Assert::assertContains($assembly['mode'], ['blueprint', 'pool', 'inline']);

            // Validate assertions
            Assert::assertArrayHasKey('assertions', $assembly);
        }
    }
}
