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

        $extractedPreview = $extractedTextPreview
            ? mb_substr($extractedTextPreview, 0, 500).'...'
            : 'No document text available';

        return <<<PROMPT
**Initial Identity Verdict:**
{$identityJson}

**Family Ruleset for "{$family}":**
{$rulesetJson}

**Available Evidence:**
- User Input: {$userInputJson}
- Extracted Text Preview: {$extractedPreview}

**Your Task:**

1. **Check Required Sections**: Does the evidence mention all required sections from the ruleset?
   - If YES: add +0.03 to confidence
   - If PARTIAL (some missing): add +0.01
   - If NO: keep confidence unchanged

2. **Check Timing Consistency**: Does the evidence align with expected exam duration?
   - If YES (within tolerance): add +0.02 to confidence
   - If CLOSE (within 20% deviation): add +0.01
   - If NO: add -0.05 to confidence (may be wrong exam)

3. **Check Scoring Scale**: Does the evidence mention the expected scoring scale?
   - If YES: add +0.02 to confidence
   - If NOT MENTIONED: no change
   - If CONTRADICTS: add -0.10 (likely wrong exam)

4. **Check Signatures**: Does the evidence contain known exam signatures/markers?
   - For each signature matched: add +0.01 (max +0.03)
   - If no signatures found: no change

5. **Check Red Flags**: Are there any contradictory signals?
   - Wrong provider mentioned: -0.15
   - Wrong country/language context: -0.10
   - Conflicting exam name variants: -0.05

**Calculate Final Confidence:**
- Start with initial confidence: {$initialConfidence}
- Apply adjustments from steps 1-5
- Cap at 0.99 (we never claim 100% certainty without user confirmation)
- If final confidence < 0.90: mark status as 'uncertain'

**Output JSON:**
{
  "confidence": <float 0.0-0.99>,
  "status": "certain" | "uncertain",
  "adjustment_reason": "Brief explanation of confidence adjustment",
  "checks_performed": {
    "sections_match": true|false|"partial",
    "timing_match": true|false|"close",
    "scoring_match": true|false|"not_mentioned",
    "signatures_found": <int>,
    "red_flags": []
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
                        'timing_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['close', 'not_mentioned']],
                            ],
                        ],
                        'scoring_match' => [
                            'oneOf' => [
                                ['type' => 'boolean'],
                                ['type' => 'string', 'enum' => ['not_mentioned']],
                            ],
                        ],
                        'signatures_found' => [
                            'type' => 'integer',
                            'minimum' => 0,
                        ],
                        'red_flags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
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
