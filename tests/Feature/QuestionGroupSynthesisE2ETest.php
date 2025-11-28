<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeQuestionsJob;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\GenerationPlan;
use App\Models\GenerationTask;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Services\LanguageApp\Contracts\QuestionGroupContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E tests for question_groups synthesis pipeline
 *
 * Tests the full flow: skeleton creation → synthesis → UPDATE → validation
 *
 * @see docs/architecture/synthesis-pipeline-rollout-plan.md (STEP S3)
 *
 * @group broken
 */
class QuestionGroupSynthesisE2ETest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test full pipeline: research → skeleton → synthesis → update
     *
     * This test verifies that:
     * 1. Skeleton questions are created during research phase
     * 2. Synthesis job updates skeletons (not duplicates)
     * 3. group_id is preserved through pipeline
     * 4. generated_questions_v2 is synchronized
     */
    public function test_listening_question_groups_synthesis_updates_skeletons(): void
    {
        // 1. Setup: Create exam with listening question_groups structure
        $exam = Exam::factory()->create([
            'title' => 'Test Listening Exam',
            'research_status' => 'phase_b_completed',  // Ready for synthesis
        ]);

        $exam->meta = [
            'structure_v2' => [
                'sections' => [
                    [
                        'key' => 'listening',
                        'question_groups' => [
                            [
                                'id' => 'listening-task-1',
                                'title' => 'Task I',
                                'questions' => [
                                    ['id' => 'list-q1', 'type' => 'listen_mcq'],
                                    ['id' => 'list-q2', 'type' => 'listen_true_false'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $exam->save();

        // 2. Simulate Research: Create skeleton questions (via StructureMaterializer)
        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'name' => 'Listening',
            'key' => 'listening',
        ]);

        $group = QuestionGroup::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'group_id' => 'listening-task-1',
            'title' => 'Task I',
            'instructions' => [],
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'order' => 0,
        ]);

        Question::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
            'question_id' => 'listening-task-1_list-q1',
            'type' => 'listen_mcq',
            'order' => 0,
            'skills_measured' => [],
            'time_limit_sec' => 0,
            'instructions' => [],
            'stimulus' => [],
            'interaction' => [],  // SKELETON
            'response' => [],
            'scoring' => [],
            'metadata' => [],
            'status' => 'draft',
            'requires_audio' => false,
        ]);

        Question::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'question_group_id' => $group->id,
            'question_id' => 'listening-task-1_list-q2',
            'type' => 'listen_true_false',
            'order' => 1,
            'skills_measured' => [],
            'time_limit_sec' => 0,
            'instructions' => [],
            'stimulus' => [],
            'interaction' => [],  // SKELETON
            'response' => [],
            'scoring' => [],
            'metadata' => [],
            'status' => 'draft',
            'requires_audio' => false,
        ]);

        // 3. Verify skeleton created
        $this->assertDatabaseHas('questions', [
            'exam_id' => $exam->id,
            'question_id' => 'listening-task-1_list-q1',
        ]);

        $skeleton1 = Question::where('question_id', 'listening-task-1_list-q1')->first();
        $this->assertEmpty($skeleton1->interaction);

        // 4. Create GenerationPlan for synthesis
        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'type' => 'synthesis',
            'status' => 'pending',
            'total_questions' => 2,
            'plan_data' => [
                'mode' => 'inline',
                'question_groups' => [
                    [
                        'id' => 'listening-task-1',
                        'title' => 'Task I',
                        'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
                        'instructions' => [],
                        'questions' => [
                            ['id' => 'list-q1', 'type' => 'listen_mcq'],
                            ['id' => 'list-q2', 'type' => 'listen_true_false'],
                        ],
                    ],
                ],
            ],
        ]);

        // 5. Create GenerationTask for synthesis
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'synthesize_questions',
            'status' => 'queued',
            'request' => [
                'plans_count' => 1,
                'total_questions' => 2,
            ],
        ]);

        // 6. Run Synthesis (sync mode for testing)
        // Disable task-level parallelization for E2E test to avoid queue dispatching
        config(['ai.use_task_level_parallelization' => false]);

        $job = new SynthesizeQuestionsJob($task->id);

        // Enable logging for debugging
        \Illuminate\Support\Facades\Log::info('[TEST] Running synthesis job', [
            'task_id' => $task->id,
            'plan_id' => $plan->id,
            'exam_id' => $exam->id,
            'use_task_level_parallelization' => config('ai.use_task_level_parallelization'),
        ]);

        try {
            $job->handle();
        } catch (\Throwable $e) {
            dump([
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => array_slice($e->getTrace(), 0, 5),
            ]);
            throw $e;
        }

        // Check task result
        $task->refresh();
        \Illuminate\Support\Facades\Log::info('[TEST] Synthesis job completed', [
            'task_status' => $task->status,
            'task_result' => $task->result,
            'task_error' => $task->error,
        ]);

        // Check plan status
        $plan->refresh();
        dump([
            'plan_status' => $plan->status,
            'plan_error' => $plan->error,
            'plan_meta' => $plan->meta,
        ]);

        // Check task status
        $task->refresh();
        if ($task->status === 'failed') {
            dump([
                'task_error' => $task->error,
                'task_result' => $task->result,
            ]);
        }

        // 7. Verify skeleton UPDATED (not duplicated)
        $allQuestions = Question::where('exam_id', $exam->id)->get();
        dump([
            'total_count' => $allQuestions->count(),
            'questions' => $allQuestions->map(fn ($q) => [
                'id' => $q->id,
                'question_id' => $q->question_id,
                'type' => $q->type,
                'has_interaction' => ! empty($q->interaction) && is_array($q->interaction) && count($q->interaction) > 0,
                'created' => $q->created_at->format('H:i:s'),
                'updated' => $q->updated_at->format('H:i:s'),
            ])->toArray(),
        ]);

        $this->assertEquals(
            2,
            $allQuestions->count(),
            'Should still have 2 questions (no duplicates)'
        );

        $updated1 = Question::where('question_id', 'listening-task-1_list-q1')->first();

        // DEBUG: Check what we got
        if (empty($updated1->interaction)) {
            dump([
                'question_id' => $updated1->question_id,
                'interaction' => $updated1->interaction,
                'type' => $updated1->type,
                'status' => $updated1->status,
                'all_questions' => Question::where('exam_id', $exam->id)->get()->toArray(),
            ]);
        }

        $this->assertNotEmpty($updated1->interaction, 'Skeleton should be filled');
        $this->assertIsArray($updated1->interaction);

        // DEBUG: Check interaction structure
        if (! array_key_exists('stem', $updated1->interaction)) {
            dump([
                'interaction_keys' => array_keys($updated1->interaction),
                'interaction_content' => $updated1->interaction,
            ]);
        }

        $this->assertArrayHasKey('stem', $updated1->interaction);

        $updated2 = Question::where('question_id', 'listening-task-1_list-q2')->first();
        $this->assertNotEmpty($updated2->interaction, 'Skeleton should be filled');
        $this->assertIsArray($updated2->interaction);

        // 8. Verify group_id preserved
        $this->assertEquals($group->id, $updated1->question_group_id);
        $this->assertEquals($group->id, $updated2->question_group_id);

        // 9. Verify generated_questions_v2 sync
        $exam->refresh();
        $this->assertArrayHasKey('generated_questions_v2', $exam->meta);
        $this->assertCount(2, $exam->meta['generated_questions_v2']);

        $generatedQ1 = collect($exam->meta['generated_questions_v2'])
            ->firstWhere('id', 'list-q1');

        $this->assertNotNull($generatedQ1);
        $this->assertEquals('listening-task-1', $generatedQ1['group_id']);
    }

    /**
     * Test that contract validation catches broken filters
     *
     * This ensures runtime validation prevents silent failures
     */
    public function test_contract_validation_prevents_invalid_filters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter must have question_id');

        QuestionGroupContract::validateFilter([
            'type' => 'listen_mcq',
            // Missing question_id!
            'group_id' => 'listening-task-1',
        ]);
    }

    /**
     * E2E Test: FK Bug Fix Verification
     *
     * Verifies that the fix in JsonSchemaQuestionV2 (lines 93-104) correctly
     * preserves group_id field through the full synthesis pipeline.
     *
     * Before fix: group_id was stripped during validation → FK = NULL in DB
     * After fix: group_id preserved → FK correctly set to question_group_id
     *
     * See: docs/bugs/fk-bug-final-resolution-2025-11-27.md
     */
    public function test_fk_bug_fix_group_id_survives_validation_and_sets_fk(): void
    {
        // 1. Create exam with inline question_groups structure
        $exam = Exam::factory()->create([
            'title' => 'FK Bug Fix E2E Test',
            'research_status' => 'phase_b_completed',
        ]);

        $category = ExamCategory::factory()->create([
            'exam_id' => $exam->id,
            'name' => 'Listening',
            'key' => 'listening',
            'skill' => 'listening',
        ]);

        // 2. Create QuestionGroup
        $group = QuestionGroup::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'group_id' => 'listening-part-1',
            'title' => 'Part 1',
            'instructions' => ['brief' => 'Listen carefully', 'full' => 'Listen and answer'],
            'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
            'playback_settings' => ['max_plays' => 1, 'enforcement' => 'strict'],
            'order' => 0,
        ]);

        // 3. Create GenerationPlan with question_groups
        $plan = GenerationPlan::create([
            'exam_id' => $exam->id,
            'section_id' => $category->id,
            'assembly_mode' => 'inline',
            'type' => 'synthesis',
            'status' => 'pending',
            'total_questions' => 2,
            'plan_data' => [
                'mode' => 'inline',
                'question_groups' => [
                    [
                        'id' => 'listening-part-1',
                        'title' => 'Part 1',
                        'stimulus' => ['audio' => ['https://example.com/audio.mp3']],
                        'instructions' => ['brief' => 'Listen carefully', 'full' => 'Listen and answer'],
                        'questions' => [
                            ['id' => 'q1', 'type' => 'dictation'],
                            ['id' => 'q2', 'type' => 'dictation'],
                        ],
                    ],
                ],
            ],
        ]);

        // 4. Create GenerationTask and run synthesis
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'synthesize_questions',
            'status' => 'queued',
            'request' => [
                'plans_count' => 1,
                'total_questions' => 2,
            ],
        ]);

        // Disable task-level parallelization for sync execution
        config(['ai.use_task_level_parallelization' => false]);

        $job = new SynthesizeQuestionsJob($task->id);

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $this->fail("Synthesis job failed: {$e->getMessage()}");
        }

        // 5. CRITICAL ASSERTIONS: Verify FK is set correctly
        $questions = Question::where('exam_id', $exam->id)->get();

        $this->assertGreaterThan(0, $questions->count(), 'Questions should be created');

        foreach ($questions as $question) {
            // ✅ ASSERT: FK must be set (this was NULL before fix)
            $this->assertNotNull(
                $question->question_group_id,
                "Question {$question->question_id} should have question_group_id FK set"
            );

            // ✅ ASSERT: FK must reference the correct QuestionGroup
            $this->assertEquals(
                $group->id,
                $question->question_group_id,
                "Question {$question->question_id} FK should reference listening-part-1 group"
            );

            // ✅ ASSERT: question_id format should include group_id
            $this->assertStringContainsString(
                'listening-part-1',
                $question->question_id,
                'Grouped question should have group_id in question_id'
            );
        }

        // 6. Verify group_id preserved in generated_questions_v2
        $exam->refresh();
        $this->assertArrayHasKey('generated_questions_v2', $exam->meta);

        $generatedQuestions = $exam->meta['generated_questions_v2'];
        foreach ($generatedQuestions as $gq) {
            $this->assertArrayHasKey('group_id', $gq, 'Generated question should have group_id field');
            $this->assertEquals('listening-part-1', $gq['group_id'], 'group_id should match filter group_id');
        }
    }
}
