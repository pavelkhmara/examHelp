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

        // Try to resolve family using aliases (v2 feature)
        $familyRaw = $examDocDraft['canonical']['family'] ?? $examDocDraft['family'] ?? null;
        $family = $this->resolveFamilyAlias($familyRaw, $ruleset);

        if (! $family || ! isset($ruleset['families'][$family])) {
            // Fallback to old structure
            if ($familyRaw && isset($ruleset[$familyRaw])) {
                $rules = $ruleset[$familyRaw];
                $family = $familyRaw;
            } else {
                return $this->createWarningResult($familyRaw);
            }
        } else {
            $rules = $ruleset['families'][$family];
        }

        // Extract base_template for defaults
        $baseTemplate = $ruleset['base_template'] ?? [];

        // Extract weights from v2 format (merge with base_template)
        $baseWeights = $baseTemplate['checks']['weights'] ?? [
            'sections_required' => 0.35,
            'timing_exam_total' => 0.25,
            'timing_sections' => 0.10,
            'timing_steps' => 0.05,
            'scoring_scale' => 0.15,
            'signatures_present' => 0.10,
        ];
        $weights = $rules['checks']['weights'] ?? $baseWeights;

        $baseThreshold = $baseTemplate['checks']['threshold'] ?? 0.85;
        $threshold = $rules['checks']['threshold'] ?? $baseThreshold;

        $passes = [];
        $fails = [];
        $warnings = [];
        $missing = [];
        $weightedScores = [];

        // Check sections (weighted)
        if (isset($rules['sections']['required'])) {
            $sectionScore = $this->checkSectionsWeighted(
                $rules['sections']['required'],
                $examDocDraft,
                $passes,
                $fails
            );
            $weightedScores['sections'] = $sectionScore * $weights['sections_required'];
        }

        // Check timing hierarchy (weighted) - PRIORITY 1: Exam Total
        if (isset($rules['timing']['total_minutes'])) {
            $examTotalScore = $this->checkExamTotalTimingWeighted(
                $rules['timing']['total_minutes'],
                $rules['timing']['tolerance_minutes'] ?? 10,
                $examDocDraft,
                $passes,
                $fails,
                $missing
            );
            $weightedScores['timing_exam_total'] = $examTotalScore * $weights['timing_exam_total'];
        }

        // PRIORITY 2: Section durations
        if (isset($rules['sections']['required'])) {
            $sectionsTimingScore = $this->checkSectionTimingsWeighted(
                $rules['sections']['required'],
                $examDocDraft,
                $passes,
                $fails,
                $missing
            );
            $weightedScores['timing_sections'] = $sectionsTimingScore * $weights['timing_sections'];
        }

        // PRIORITY 3: Step/task durations (lowest priority, optional)
        $stepsTimingScore = $this->checkStepTimingsWeighted(
            $examDocDraft,
            $passes,
            $warnings  // Use warnings, not fails - step timing is optional
        );
        $weightedScores['timing_steps'] = $stepsTimingScore * $weights['timing_steps'];

        // Check scoring scale (weighted)
        if (isset($rules['scoring']['scale'])) {
            $scoringScore = $this->checkScoringScaleWeighted(
                $rules['scoring']['scale'],
                $examDocDraft,
                $passes,
                $fails,
                $missing
            );
            $weightedScores['scoring'] = $scoringScore * $weights['scoring_scale'];
        }

        // Check signatures (weighted, including regex)
        if (isset($rules['signatures'])) {
            $signaturesScore = $this->checkSignaturesWeighted(
                $rules['signatures'],
                $examDocDraft,
                $passes,
                $fails
            );
            $weightedScores['signatures'] = $signaturesScore * $weights['signatures_present'];
        }

        // Calculate weighted compliance score
        $compliance = array_sum($weightedScores);

        // Add warning if below threshold
        if ($compliance < $threshold) {
            $warnings[] = "Weighted compliance score ({$compliance}) below threshold ({$threshold})";
        }

        $result = [
            'compliance_score' => round($compliance, 2),
            'weighted_scores' => $weightedScores,
            'weights' => $weights,
            'threshold' => $threshold,
            'passes' => $passes,
            'fails' => $fails,
            'warnings' => $warnings,
            'missing' => $missing,
            'anchors' => [],
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

    /**
     * Resolve family alias (v2 feature)
     */
    protected function resolveFamilyAlias(?string $familyRaw, array $ruleset): ?string
    {
        if (! $familyRaw) {
            return null;
        }

        // Check if aliases exist in v2 format
        if (isset($ruleset['aliases'][$familyRaw])) {
            return $ruleset['aliases'][$familyRaw];
        }

        return $familyRaw;
    }

    /**
     * Check sections with weighted scoring (v2)
     */
    protected function checkSectionsWeighted(array $requiredSections, array $examDocDraft, array &$passes, array &$fails): float
    {
        $examSections = $examDocDraft['sections'] ?? [];
        $foundSections = array_column($examSections, 'name');
        $foundCount = 0;

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
                $foundCount++;
            } else {
                $fails[] = "Required section '{$reqSection}' missing";
            }
        }

        // Return score 0.0-1.0
        return count($requiredSections) > 0 ? ($foundCount / count($requiredSections)) : 0.0;
    }

    /**
     * Check exam total timing with weighted scoring (v2) - PRIORITY 1 (HIGHEST)
     */
    protected function checkExamTotalTimingWeighted(array $timingRange, int $tolerance, array $examDocDraft, array &$passes, array &$fails, array &$missing): float
    {
        [$minTime, $maxTime] = $timingRange;
        $examTime = $examDocDraft['administration']['total_time_minutes'] ?? null;

        if ($examTime === null) {
            $missing[] = 'Total exam time not specified (CRITICAL - highest priority)';

            return 0.0; // Critical failure when missing
        }

        if ($examTime >= ($minTime - $tolerance) && $examTime <= ($maxTime + $tolerance)) {
            $passes[] = "Total exam time {$examTime}min within expected range [{$minTime}-{$maxTime}] ✓ (CRITICAL)";

            return 1.0;
        } elseif ($examTime >= ($minTime - ($tolerance * 2)) && $examTime <= ($maxTime + ($tolerance * 2))) {
            $passes[] = "Total exam time {$examTime}min close to expected range [{$minTime}-{$maxTime}] (CRITICAL)";

            return 0.7; // Close match
        } else {
            $fails[] = "Total exam time {$examTime}min outside expected range [{$minTime}-{$maxTime}] (CRITICAL)";

            return 0.0;
        }
    }

    /**
     * Check section timings with weighted scoring - PRIORITY 2 (MEDIUM)
     */
    protected function checkSectionTimingsWeighted(array $requiredSections, array $examDocDraft, array &$passes, array &$fails, array &$missing): float
    {
        $sections = $examDocDraft['sections'] ?? [];
        if (empty($sections)) {
            $missing[] = 'No sections found in exam doc';
            return 0.5; // Neutral
        }

        $totalExpected = count($requiredSections);
        $foundWithTiming = 0;

        foreach ($requiredSections as $reqSection) {
            $found = false;
            $hasTiming = false;

            foreach ($sections as $section) {
                $sectionName = $section['name'] ?? '';
                if (stripos($sectionName, $reqSection) !== false) {
                    $found = true;
                    // Check if section has duration information
                    if (isset($section['duration_minutes']) || isset($section['time_minutes'])) {
                        $hasTiming = true;
                        $duration = $section['duration_minutes'] ?? $section['time_minutes'];
                        $passes[] = "Section '{$reqSection}' has timing: {$duration} min ✓";
                        $foundWithTiming++;
                    }
                    break;
                }
            }

            if ($found && !$hasTiming) {
                $fails[] = "Section '{$reqSection}' found but missing timing";
            }
        }

        if ($totalExpected === 0) {
            return 0.5; // Neutral
        }

        // Score based on sections with timing / total expected
        $score = $foundWithTiming / $totalExpected;
        return $score;
    }

    /**
     * Check step/task timings with weighted scoring - PRIORITY 3 (LOWEST, OPTIONAL)
     */
    protected function checkStepTimingsWeighted(array $examDocDraft, array &$passes, array &$warnings): float
    {
        $sections = $examDocDraft['sections'] ?? [];
        if (empty($sections)) {
            return 0.5; // Neutral, not critical
        }

        $totalSteps = 0;
        $stepsWithTiming = 0;

        foreach ($sections as $section) {
            $steps = $section['steps'] ?? [];
            foreach ($steps as $step) {
                $totalSteps++;
                if (isset($step['duration_minutes']) || isset($step['step_duration'])) {
                    $stepsWithTiming++;
                    $duration = $step['duration_minutes'] ?? $step['step_duration'];
                    $passes[] = "Step/task has timing: {$duration} min ✓ (optional)";
                }
            }
        }

        if ($totalSteps === 0) {
            return 0.5; // Neutral, no steps found
        }

        $ratio = $stepsWithTiming / $totalSteps;

        if ($ratio < 0.3) {
            $warnings[] = "Only {$stepsWithTiming} of {$totalSteps} tasks have timing (optional, low priority)";
        }

        // Return score based on coverage
        return $ratio;
    }

    /**
     * Check scoring scale with weighted scoring (v2)
     */
    protected function checkScoringScaleWeighted(string $expectedScale, array $examDocDraft, array &$passes, array &$fails, array &$missing): float
    {
        $examScale = $examDocDraft['scoring']['scale'] ?? null;

        if ($examScale === null) {
            $missing[] = 'Scoring scale not specified';

            return 0.5; // Neutral score when missing
        }

        if ($examScale === $expectedScale) {
            $passes[] = "Scoring scale '{$examScale}' matches expected '{$expectedScale}' ✓";

            return 1.0;
        } elseif (stripos($examScale, $expectedScale) !== false || stripos($expectedScale, $examScale) !== false) {
            $passes[] = "Scoring scale '{$examScale}' similar to expected '{$expectedScale}'";

            return 0.7; // Similar match
        } else {
            $fails[] = "Scoring scale '{$examScale}' != expected '{$expectedScale}'";

            return 0.0;
        }
    }

    /**
     * Check signatures with weighted scoring and regex support (v2)
     */
    protected function checkSignaturesWeighted(array $signatures, array $examDocDraft, array &$passes, array &$fails): float
    {
        $examText = json_encode($examDocDraft, JSON_UNESCAPED_UNICODE);
        $totalSignatures = count($signatures);
        $foundSignatures = 0;
        $weightedScore = 0.0;
        $totalWeight = 0.0;

        foreach ($signatures as $sig) {
            if (is_array($sig) && isset($sig['pattern'])) {
                // Regex pattern
                $pattern = '/'.$sig['pattern'].'/';
                $flags = $sig['flags'] ?? '';
                if (str_contains($flags, 'i')) {
                    $pattern .= 'i';
                }

                $weight = $sig['weight'] ?? 0.1;
                $totalWeight += $weight;

                if (@preg_match($pattern, $examText)) {
                    $passes[] = "Signature pattern '{$sig['pattern']}' matched ✓";
                    $foundSignatures++;
                    $weightedScore += $weight;
                } else {
                    $fails[] = "Signature pattern '{$sig['pattern']}' not found";
                }
            } else {
                // String match
                $sigText = is_string($sig) ? $sig : '';
                $totalWeight += 0.1;

                if (stripos($examText, $sigText) !== false) {
                    $passes[] = "Signature '{$sigText}' found ✓";
                    $foundSignatures++;
                    $weightedScore += 0.1;
                } else {
                    $fails[] = "Signature '{$sigText}' not found";
                }
            }
        }

        // Return normalized weighted score 0.0-1.0
        return $totalWeight > 0 ? ($weightedScore / $totalWeight) : 0.0;
    }
}
