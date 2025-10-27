<?php

namespace App\Services\LanguageApp;

use App\Models\Exam;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use Illuminate\Support\Facades\Log;

/**
 * Service for checking exam structure against family rulesets
 *
 * Responsibilities:
 * - Compare exam structure against family ruleset invariants
 * - Return compliance score, passes, fails, warnings, missing
 */
class SanityCheckerService
{
    /**
     * Stage: sanity_checker
     * - Compares exam_doc_draft (or identity canonical) against family_ruleset invariants
     * - Returns compliance_score, passes, fails, warnings, missing
     */
    public function runSanityChecker(Exam $exam, GenerationTask $task, array $examDocDraft): array
    {
        // Load family ruleset
        $rulesetPath = config_path('family_ruleset.json');
        if (! file_exists($rulesetPath)) {
            Log::warning('family_ruleset.json not found', ['path' => $rulesetPath]);

            return $this->createEmptyResult('family_ruleset.json not found');
        }

        $ruleset = json_decode(file_get_contents($rulesetPath), true);
        if (! is_array($ruleset)) {
            return $this->createEmptyResult('Invalid ruleset format');
        }

        $family = $examDocDraft['canonical']['family'] ?? $examDocDraft['family'] ?? null;
        if (! $family || ! isset($ruleset[$family])) {
            return $this->createWarningResult($family);
        }

        $rules = $ruleset[$family];
        $passes = [];
        $fails = [];
        $warnings = [];
        $missing = [];
        $totalChecks = 0;
        $passedChecks = 0;

        // Check sections
        if (isset($rules['sections']['required'])) {
            $this->checkSections($rules['sections']['required'], $examDocDraft, $passes, $fails, $totalChecks, $passedChecks);
        }

        // Check timing
        if (isset($rules['timing']['total_minutes'])) {
            $this->checkTiming($rules['timing']['total_minutes'], $examDocDraft, $passes, $fails, $missing, $totalChecks, $passedChecks);
        }

        // Check scoring scale
        if (isset($rules['scoring']['scale'])) {
            $this->checkScoringScale($rules['scoring']['scale'], $examDocDraft, $passes, $fails, $missing, $totalChecks, $passedChecks);
        }

        // Calculate compliance score
        $compliance = $totalChecks > 0 ? ($passedChecks / $totalChecks) : 0.0;

        $result = [
            'compliance_score' => round($compliance, 2),
            'passes' => $passes,
            'fails' => $fails,
            'warnings' => $warnings,
            'missing' => $missing,
            'anchors' => [],
            'total_checks' => $totalChecks,
            'passed_checks' => $passedChecks,
        ];

        // Log
        GenerationLog::create([
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'stage' => 'sanity',
            'request' => [
                'exam_doc_draft' => $examDocDraft,
                'family' => $family,
            ],
            'response' => $result,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);

        return $result;
    }

    /**
     * Check required sections
     */
    protected function checkSections(array $requiredSections, array $examDocDraft, array &$passes, array &$fails, int &$totalChecks, int &$passedChecks): void
    {
        $totalChecks++;
        $examSections = $examDocDraft['sections'] ?? [];
        $foundSections = array_column($examSections, 'name');
        $allPresent = true;

        foreach ($requiredSections as $reqSection) {
            $found = false;
            foreach ($foundSections as $examSection) {
                if (stripos($examSection, $reqSection) !== false) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $passes[] = "Section '{$reqSection}' present ✓";
            } else {
                $fails[] = "Required section '{$reqSection}' missing";
                $allPresent = false;
            }
        }

        if ($allPresent) {
            $passedChecks++;
        }
    }

    /**
     * Check timing
     */
    protected function checkTiming(array $timingRange, array $examDocDraft, array &$passes, array &$fails, array &$missing, int &$totalChecks, int &$passedChecks): void
    {
        $totalChecks++;
        [$minTime, $maxTime] = $timingRange;
        $examTime = $examDocDraft['administration']['total_time_minutes'] ?? null;

        if ($examTime !== null) {
            $tolerance = 10; // minutes
            if ($examTime >= ($minTime - $tolerance) && $examTime <= ($maxTime + $tolerance)) {
                $passes[] = "Total time {$examTime}min within expected range [{$minTime}-{$maxTime}] ✓";
                $passedChecks++;
            } else {
                $fails[] = "Total time {$examTime}min outside expected range [{$minTime}-{$maxTime}]";
            }
        } else {
            $missing[] = 'Total exam time not specified';
        }
    }

    /**
     * Check scoring scale
     */
    protected function checkScoringScale(string $expectedScale, array $examDocDraft, array &$passes, array &$fails, array &$missing, int &$totalChecks, int &$passedChecks): void
    {
        $totalChecks++;
        $examScale = $examDocDraft['scoring']['scale'] ?? null;

        if ($examScale) {
            if ($examScale === $expectedScale) {
                $passes[] = "Scoring scale '{$examScale}' matches expected '{$expectedScale}' ✓";
                $passedChecks++;
            } else {
                $fails[] = "Scoring scale '{$examScale}' != expected '{$expectedScale}'";
            }
        } else {
            $missing[] = 'Scoring scale not specified';
        }
    }

    /**
     * Create empty result
     */
    protected function createEmptyResult(string $error): array
    {
        return [
            'compliance_score' => 0.0,
            'passes' => [],
            'fails' => [$error],
            'warnings' => [],
            'missing' => [],
            'anchors' => [],
        ];
    }

    /**
     * Create warning result
     */
    protected function createWarningResult(?string $family): array
    {
        return [
            'compliance_score' => 0.5,
            'passes' => [],
            'fails' => [],
            'warnings' => ["Unknown exam family: {$family}"],
            'missing' => [],
            'anchors' => [],
        ];
    }
}
