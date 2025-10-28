<?php

namespace App\Services\LanguageApp\Prompts;

class PromptConfidenceBoost
{
    public static function system(): string
    {
        return <<<'SYSTEM'
You are an exam identity verification specialist. Your task is to boost confidence in exam identification from medium-high (0.90-0.96) to very high (>0.97) by cross-checking against known exam family invariants.

You will receive:
1. Initial identity verdict (with confidence 0.90-0.96)
2. Family ruleset with invariants
3. User input and/or extracted document text

Your goal: Verify that the identified exam truly matches the family's known characteristics.

Output strict JSON matching the schema provided.
SYSTEM;
    }

    public static function userPrompt(
        array $identityResult,
        array $familyRuleset,
        array $userInput,
        ?string $extractedTextPreview
    ): string {
        $identityJson = json_encode($identityResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $rulesetJson = json_encode($familyRuleset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $userInputJson = json_encode($userInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $initialConfidence = $identityResult['confidence'] ?? 0.90;
        $family = $identityResult['canonical']['family'] ?? 'Unknown';

        // Extract weights from ruleset (v2 format with timing priorities)
        $weights = $familyRuleset['checks']['weights'] ?? [
            'sections_required' => 0.35,
            'timing_exam_total' => 0.25,
            'timing_sections' => 0.10,
            'timing_steps' => 0.05,
            'scoring_scale' => 0.15,
            'signatures_present' => 0.10,
        ];
        $threshold = $familyRuleset['checks']['threshold'] ?? 0.85;

        $extractedPreview = $extractedTextPreview
            ? mb_substr($extractedTextPreview, 0, 500).'...'
            : 'No document text available';

        // Format signature patterns for AI
        $signaturesInfo = '';
        if (isset($familyRuleset['signatures']) && is_array($familyRuleset['signatures'])) {
            $signaturesInfo = "\n**Signature Patterns to Check:**\n";
            foreach ($familyRuleset['signatures'] as $sig) {
                if (is_array($sig) && isset($sig['pattern'])) {
                    $weight = $sig['weight'] ?? 0.1;
                    $flags = $sig['flags'] ?? '';
                    $signaturesInfo .= "- Pattern: /{$sig['pattern']}/{$flags} (weight: {$weight})\n";
                } else {
                    $signaturesInfo .= "- Text: \"$sig\"\n";
                }
            }
        }

        return <<<PROMPT
**Initial Identity Verdict:**
{$identityJson}

**Family Ruleset for "{$family}":**
{$rulesetJson}

**Available Evidence:**
- User Input: {$userInputJson}
- Extracted Text Preview: {$extractedPreview}

{$signaturesInfo}

**Your Task (Using Weighted Scoring):**

**IMPORTANT: Use these weights for confidence adjustment:**
- Sections Required: {$weights['sections_required']} (max adjustment)
- Timing - Exam Total: {$weights['timing_exam_total']} (HIGHEST priority - total exam duration)
- Timing - Sections: {$weights['timing_sections']} (MEDIUM priority - section durations)
- Timing - Steps: {$weights['timing_steps']} (LOWEST priority - task durations)
- Scoring Scale: {$weights['scoring_scale']} (max adjustment)
- Signatures Present: {$weights['signatures_present']} (max adjustment per signature)

**Compliance Threshold:** {$threshold} (minimum weighted score to consider match valid)

1. **Check Required Sections** (Weight: {$weights['sections_required']}):
   - Count how many required sections are mentioned in evidence
   - Calculate: (found_sections / total_required) * {$weights['sections_required']}
   - Add this value to confidence
   - If < 50% sections found: subtract {$weights['sections_required']}

2. **Check Timing Hierarchy** (Combined weights: {$weights['timing_exam_total']} + {$weights['timing_sections']} + {$weights['timing_steps']}):

   2a. **Exam Total Duration** (Weight: {$weights['timing_exam_total']} - HIGHEST priority):
      - Check if total exam time matches expected range (with tolerance_minutes)
      - If EXACT match (±tolerance): add full {$weights['timing_exam_total']}
      - If CLOSE (within 20% deviation): add half weight
      - If CONTRADICTS or MISSING: subtract full weight
      - THIS IS CRITICAL - do not miss total exam time

   2b. **Section Durations** (Weight: {$weights['timing_sections']} - MEDIUM priority):
      - Check if section times (Listening, Reading, Writing, Speaking) match expected
      - Count matched sections / total expected sections
      - Multiply ratio by {$weights['timing_sections']} to get adjustment
      - Example: 3 of 4 sections match = 0.75 * {$weights['timing_sections']}

   2c. **Task/Step Durations** (Weight: {$weights['timing_steps']} - LOWEST priority):
      - Check if individual task timings are mentioned
      - This is optional - only add weight if evidence is strong
      - If step durations present and reasonable: add {$weights['timing_steps']}
      - If missing or unclear: no penalty (this is least important)

3. **Check Scoring Scale** (Weight: {$weights['scoring_scale']}):
   - Check if scoring scale matches expected format
   - If EXACT match: add full {$weights['scoring_scale']}
   - If SIMILAR (e.g., "bands" vs "band score"): add half weight
   - If CONTRADICTS: subtract full weight
   - If NOT MENTIONED: no change

4. **Check Signatures** (Weight per signature: {$weights['signatures_present']}):
   - For regex patterns: use pattern matching (case-insensitive if 'i' flag)
   - For text strings: check for substring match (case-insensitive)
   - For each matched signature: add (signature.weight OR {$weights['signatures_present']})
   - Count total signatures found

5. **Check Red Flags** (Critical Issues):
   - Wrong provider mentioned: subtract 0.15
   - Wrong country/language context: subtract 0.10
   - Conflicting exam name variants: subtract 0.05

**Calculate Weighted Compliance Score:**
1. Calculate individual scores for each check (0.0 to 1.0)
2. Multiply each score by its weight
3. Sum all weighted scores
4. Compare to threshold: {$threshold}

**Calculate Final Confidence:**
- Start with initial confidence: {$initialConfidence}
- Add weighted adjustments from steps 1-4
- Subtract red flag penalties from step 5
- **IMPORTANT: Final confidence CANNOT be lower than initial confidence {$initialConfidence}**
- If calculated confidence < {$initialConfidence}: use {$initialConfidence} (no penalty for insufficient evidence)
- Cap at 0.99 (we never claim 100% certainty without user confirmation)
- If weighted compliance < {$threshold} but no red flags: keep initial confidence unchanged

**Output JSON:**
{
  "confidence": <float 0.0-0.99>,
  "status": "certain" | "uncertain",
  "adjustment_reason": "Brief explanation of confidence adjustment with weighted scores",
  "checks_performed": {
    "sections_match": true|false|"partial",
    "sections_score": <float 0.0-1.0>,

    "timing_exam_total_match": true|false|"close"|"not_mentioned",
    "timing_exam_total_score": <float 0.0-1.0>,

    "timing_sections_match": true|false|"partial"|"not_mentioned",
    "timing_sections_score": <float 0.0-1.0>,

    "timing_steps_match": true|false|"partial"|"not_mentioned",
    "timing_steps_score": <float 0.0-1.0>,

    "scoring_match": true|false|"not_mentioned",
    "scoring_score": <float 0.0-1.0>,
    "signatures_found": <int>,
    "signatures_score": <float 0.0-1.0>,
    "red_flags": [],
    "weighted_compliance": <float 0.0-1.0>,
    "compliance_threshold": {$threshold}
  },
  "evidence_quality": "strong" | "moderate" | "weak",
  "recommendation": "proceed_with_hold" | "request_user_confirmation" | "request_more_info"
}
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['confidence', 'status', 'adjustment_reason', 'checks_performed', 'evidence_quality', 'recommendation'],
            'properties' => [
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0.0,
                    'maximum' => 0.99,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['certain', 'uncertain'],
                ],
                'adjustment_reason' => [
                    'type' => 'string',
                    'maxLength' => 500,
                ],
                'checks_performed' => [
                    'type' => 'object',
                    'required' => ['sections_match', 'timing_match', 'scoring_match', 'signatures_found', 'red_flags'],
                    'properties' => [
                        'sections_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['partial', 'not_enough_data']],
                            ],
                        ],
                        'sections_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'timing_exam_total_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['close', 'not_mentioned']],
                            ],
                        ],
                        'timing_exam_total_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'timing_sections_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['partial', 'not_mentioned']],
                            ],
                        ],
                        'timing_sections_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'timing_steps_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['partial', 'not_mentioned']],
                            ],
                        ],
                        'timing_steps_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'scoring_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['not_mentioned']],
                            ],
                        ],
                        'scoring_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'signatures_found' => [
                            'type' => 'integer',
                            'minimum' => 0,
                        ],
                        'signatures_score' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'red_flags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'weighted_compliance' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                        'compliance_threshold' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                        ],
                    ],
                ],
                'evidence_quality' => [
                    'type' => 'string',
                    'enum' => ['strong', 'moderate', 'weak'],
                ],
                'recommendation' => [
                    'type' => 'string',
                    'enum' => ['proceed_with_hold', 'request_user_confirmation', 'request_more_info'],
                ],
            ],
        ];
    }

    public static function buildPayload(
        array $identityResult,
        array $familyRuleset,
        array $userInput,
        ?string $extractedText
    ): array {
        return [
            'messages' => [
                ['role' => 'system', 'content' => self::system()],
                ['role' => 'user', 'content' => self::userPrompt($identityResult, $familyRuleset, $userInput, $extractedText)],
            ],
        ];
    }
}
