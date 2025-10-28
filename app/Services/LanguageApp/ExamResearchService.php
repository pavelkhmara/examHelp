<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationTask;

/**
 * Main service for exam research pipeline
 *
 * This service delegates to specialized services for each stage:
 * - IdentityGuardService: Exam identity verification (single attempt)
 * - IterativeIdentityVerificationService: Multi-attempt iterative verification with web search
 * - ConfidenceBoostService: Confidence boosting
 * - SanityCheckerService: Structure validation
 * - OverviewStructureBuilder: Structure building
 * - ExampleGenerationService: Example generation
 * - AutoClarificationService: Auto-clarification
 */
class ExamResearchService extends AbstractAiService
{
    public function __construct(
        AiProvider $ai,
        protected IdentityGuardService $identityGuard,
        protected IterativeIdentityVerificationService $iterativeVerification,
        protected ConfidenceBoostService $confidenceBoost,
        protected SanityCheckerService $sanityChecker,
        protected OverviewStructureBuilder $structureBuilder,
        protected ExampleGenerationService $exampleGenerator,
        protected AutoClarificationService $autoClarification,
        protected ?DocumentStructureExtractor $documentExtractor = null
    ) {
        parent::__construct($ai);
    }

    /**
     * Run the main overview pipeline - delegates to OverviewStructureBuilder
     */
    public function runPipeline(Exam $exam, GenerationTask $task): array
    {
        return $this->structureBuilder->runPipeline($exam, $task);
    }

    /**
     * Run identity guard - delegates to IdentityGuardService (single attempt)
     */
    public function runIdentityGuard(Exam $exam, GenerationTask $task): array
    {
        return $this->identityGuard->runIdentityGuard($exam, $task);
    }

    /**
     * Run iterative identity verification - delegates to IterativeIdentityVerificationService
     * This method attempts to verify exam identity with up to 3 attempts,
     * using web search and adaptive prompts to improve confidence
     */
    public function runIterativeIdentityVerification(Exam $exam, GenerationTask $task): array
    {
        return $this->iterativeVerification->runIterativeVerification($exam, $task);
    }

    /**
     * Run variant probe - delegates to IdentityGuardService
     */
    public function runVariantProbe(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        return $this->identityGuard->runVariantProbe($exam, $task, $identityResult);
    }

    /**
     * Run confidence boost - delegates to ConfidenceBoostService
     */
    public function runConfidenceBoost(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        return $this->confidenceBoost->runConfidenceBoost($exam, $task, $identityResult);
    }

    /**
     * Run sanity checker - delegates to SanityCheckerService
     */
    public function runSanityChecker(Exam $exam, GenerationTask $task, array $examDocDraft): array
    {
        return $this->sanityChecker->runSanityChecker($exam, $task, $examDocDraft);
    }

    /**
     * Generate examples - delegates to ExampleGenerationService
     */
    public function generateExamples(Exam $exam, GenerationTask $task, int $examplesPerArchetype = 1): array
    {
        return $this->exampleGenerator->generateExamples($exam, $task, $examplesPerArchetype);
    }

    /**
     * Run auto-clarification - delegates to AutoClarificationService
     */
    public function runAutoClarification(Exam $exam, GenerationTask $task, array $identityResult): array
    {
        return $this->autoClarification->runAutoClarification($exam, $task, $identityResult);
    }

    /**
     * Import AI JSON (legacy method, kept for compatibility)
     */
    public function importAiJson(Exam $exam, array $tasks): void
    {
        GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'import_validated',
            'status' => 'completed',
            'result' => ['tasks' => $tasks],
        ]);
    }

    /**
     * Coerce overview for tests - delegates to OverviewStructureBuilder
     * This is used by tests for soft validation fallback
     */
    public function coerceOverviewForTests(array $decoded): array
    {
        // Access protected method via reflection for tests
        $reflection = new \ReflectionClass($this->structureBuilder);
        $method = $reflection->getMethod('coerceOverviewForTests');
        $method->setAccessible(true);

        return $method->invoke($this->structureBuilder, $decoded);
    }
}
