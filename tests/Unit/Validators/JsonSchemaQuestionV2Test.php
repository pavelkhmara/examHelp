<?php

namespace Tests\Unit\Validators;

use App\Services\LanguageApp\Validators\JsonSchemaQuestionV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JsonSchemaQuestionV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_valid_question_passes_validation(): void
    {
        $validator = new JsonSchemaQuestionV2;

        $q = [
            'id' => 'q1',
            'version' => '2.0',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'time_limit_sec' => 90,
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose best answer'],
            'stimulus' => ['text_html' => '<p>Passage</p>'],
            'interaction' => [
                'response_type' => 'selection',
                'options' => [
                    ['id' => 'A', 'label' => 'Option A'],
                    ['id' => 'B', 'label' => 'Option B'],
                ],
            ],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'medium', 'topic' => 'test', 'language' => 'en'],
        ];

        $validated = $validator->validate($q);

        $this->assertIsArray($validated);
        $this->assertSame('single_select', $validated['type']);
    }

    /**
     * Test that group_id field is preserved during validation.
     * This is critical for Question Groups FK relationship.
     * See: docs/bugs/fk-bug-final-resolution-2025-11-27.md
     */
    public function test_preserves_group_id_field(): void
    {
        $validator = new JsonSchemaQuestionV2;

        $q = [
            'id' => 'q1',
            'version' => '2.0',
            'type' => 'dictation',
            'skills_measured' => ['listening'],
            'time_limit_sec' => 90,
            'instructions' => ['brief' => 'Listen', 'full' => 'Listen and type'],
            'stimulus' => ['audio' => ['test.mp3']],
            'interaction' => ['response_type' => 'text_input'],
            'response' => ['mode' => 'text'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['correct answer'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'medium', 'topic' => 'test', 'language' => 'en'],
            // Pipeline metadata field (CRITICAL for FK)
            'group_id' => 'listening-part-1',
        ];

        $validated = $validator->validate($q);

        // ✅ ASSERT: group_id must be preserved
        $this->assertArrayHasKey('group_id', $validated, 'group_id field must be preserved');
        $this->assertSame('listening-part-1', $validated['group_id'], 'group_id value must match input');
    }

    /**
     * Test that all pipeline metadata fields are preserved.
     * These fields are added by pipeline stages and must survive validation.
     * See: docs/bugs/fk-bug-final-resolution-2025-11-27.md
     */
    public function test_preserves_all_pipeline_metadata_fields(): void
    {
        $validator = new JsonSchemaQuestionV2;

        $q = [
            'id' => 'q1',
            'version' => '2.0',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'time_limit_sec' => 90,
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose best answer'],
            'stimulus' => ['text_html' => '<p>Passage</p>'],
            'interaction' => ['response_type' => 'selection', 'options' => [['id' => 'A', 'label' => 'A']]],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'medium', 'topic' => 'test', 'language' => 'en'],
            // All pipeline metadata fields
            'group_id' => 'reading-part-2',
            'is_duplicate' => true,
            'duplicate_of' => 'q0',
            'similarity_score' => 0.95,
        ];

        $validated = $validator->validate($q);

        // ✅ ASSERT: All pipeline metadata fields must be preserved
        $this->assertArrayHasKey('group_id', $validated);
        $this->assertSame('reading-part-2', $validated['group_id']);

        $this->assertArrayHasKey('is_duplicate', $validated);
        $this->assertSame(true, $validated['is_duplicate']);

        $this->assertArrayHasKey('duplicate_of', $validated);
        $this->assertSame('q0', $validated['duplicate_of']);

        $this->assertArrayHasKey('similarity_score', $validated);
        $this->assertSame(0.95, $validated['similarity_score']);
    }

    /**
     * Test that pipeline metadata fields use defaults when not provided.
     */
    public function test_uses_defaults_for_missing_pipeline_metadata(): void
    {
        $validator = new JsonSchemaQuestionV2;

        $q = [
            'id' => 'q1',
            'version' => '2.0',
            'type' => 'single_select',
            'skills_measured' => ['reading'],
            'time_limit_sec' => 90,
            'instructions' => ['brief' => 'Choose', 'full' => 'Choose best answer'],
            'stimulus' => ['text_html' => '<p>Passage</p>'],
            'interaction' => ['response_type' => 'selection', 'options' => [['id' => 'A', 'label' => 'A']]],
            'response' => ['mode' => 'selection'],
            'scoring' => ['method' => 'keyed', 'answer_key' => ['A'], 'max_score' => 1],
            'metadata' => ['cefr' => ['B1'], 'difficulty' => 'medium', 'topic' => 'test', 'language' => 'en'],
            // NO pipeline metadata fields provided
        ];

        $validated = $validator->validate($q);

        // ✅ ASSERT: Default values used when fields not provided
        $this->assertArrayHasKey('group_id', $validated);
        $this->assertNull($validated['group_id'], 'group_id should default to null');

        $this->assertArrayHasKey('is_duplicate', $validated);
        $this->assertFalse($validated['is_duplicate'], 'is_duplicate should default to false');

        $this->assertArrayHasKey('duplicate_of', $validated);
        $this->assertNull($validated['duplicate_of'], 'duplicate_of should default to null');

        $this->assertArrayHasKey('similarity_score', $validated);
        $this->assertSame(0.0, $validated['similarity_score'], 'similarity_score should default to 0.0');
    }
}
