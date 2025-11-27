<?php

namespace App\Services\LanguageApp\Prompts;

use App\Services\LanguageApp\Validators\JsonSchemaExamIdentity;

/**
 * Prompt for identity_guard stage
 *
 * Purpose: Determine exam family, provider, variant based on user_input and extracted_text
 * Returns: identity verdict with confidence, canonical info, candidates, followups
 */
final class PromptIdentityGuard
{
    public static function system(): string
    {
        return <<<'SYSTEM'
You are an evidence-bound exam identity analyzer. Your task is to determine the exact exam family, provider, and variant from user input and document text.

**Core Principles:**
1. Use ONLY provided data: user_input, extracted_text, and marker_pack
2. NO speculation or assumptions beyond evidence
3. Return compact JSON matching the required schema
4. If uncertain, provide specific follow-up questions

**Exam Families to Consider:**
- IELTS (Academic/General Training) - Cambridge/IDP/British Council
- TOEFL iBT/Paper - ETS
- Goethe-Zertifikat (A1-C2) - Goethe-Institut
- DELF/DALF (A1-C2) - France Éducation international
- telc (multiple languages) - telc gGmbH
- Cambridge English (KET, PET, FCE, CAE, CPE)
- PTE Academic - Pearson
- Language-specific exams (German: TestDaF, DSH; French: TCF, TEF; etc.)

**Evidence to Look For:**
1. **Direct mentions:** Official exam names, provider logos, certification bodies
2. **Structural signatures:** Section names, timing patterns, question counts
3. **Terminology:** Unique phrases ("band score", "scaled score", "integrated tasks")
4. **Languages:** Test language, instruction language
5. **Scoring patterns:** 0-9 bands, 0-120 scores, percentage-based
6. **Format clues:** Paper/computer, individual/pair speaking

**Confidence Scoring:**
- 0.95-1.0: Official document with explicit naming and provider
- 0.85-0.94: Strong structural match + terminology alignment
- 0.70-0.84: Partial match, missing some key signatures
- 0.50-0.69: Ambiguous, need clarification
- 0.00-0.49: Insufficient evidence

**When to Mark "uncertain":**
- Confidence < 0.80
- Multiple candidates with similar scores
- Missing critical fields (language, variant, provider)
- Contradictory evidence
- Third-party sample without clear attribution

**Required Output Format (strict JSON):**
```json
{
  "status": "certain|uncertain",
  "confidence": 0.92,
  "canonical": {
    "family": "IELTS",
    "name": "IELTS Academic",
    "provider": "Cambridge Assessment English / IDP / British Council",
    "variant": "Academic",
    "language_of_test": "English"
  },
  "candidates": [
    {
      "family": "IELTS",
      "name": "IELTS Academic",
      "provider": "Cambridge/IDP/BC",
      "score": 0.92
    }
  ],
  "followups": [],
  "need_fields": [],
  "anchors": [
    {
      "page": 1,
      "snippet": "IELTS Academic - Listening Section - Time: approximately 30 minutes (plus 10 minutes transfer time)"
    }
  ]
}
```

**For uncertain cases, provide 2-4 targeted follow-up questions:**
- Each question must be atomic and actionable
- Include "why" explanation showing impact on identification
- Example: "Is this Academic or General Training? (affects Reading text types and Writing tasks)"
SYSTEM;
    }

    public static function userPrompt(array $userInput, ?string $extractedText, array $markerPack = [], ?array $existingIdentity = null, ?array $userMeta = null): string
    {
        // Extract iteration context and web search data if present
        $iterationContext = $userInput['iteration_context'] ?? null;
        $webSearchData = $userInput['web_search_data'] ?? null;

        // Clean user input for display (remove internal tracking fields)
        $cleanUserInput = $userInput;
        unset($cleanUserInput['iteration_context'], $cleanUserInput['web_search_data']);

        $userJson = json_encode($cleanUserInput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $textSnippet = $extractedText
            ? (mb_strlen($extractedText) > 3000
                ? mb_substr($extractedText, 0, 3000)."\n\n[...text truncated for brevity...]"
                : $extractedText)
            : '[No document text provided]';

        $markerInfo = ! empty($markerPack)
            ? 'Marker Pack Available: '.json_encode(array_keys($markerPack), JSON_UNESCAPED_UNICODE)
            : '[No marker pack provided]';

        // Build iteration context section
        $iterationSection = '';
        if ($iterationContext && is_array($iterationContext)) {
            $iterationNumber = $iterationContext['iteration_number'] ?? 1;
            $previousAttempts = $iterationContext['previous_attempts'] ?? [];

            if (! empty($previousAttempts)) {
                $iterationSection = "\n\n**PREVIOUS VERIFICATION ATTEMPTS:**\n";
                $iterationSection .= "This is attempt #{$iterationNumber}. Previous attempts:\n\n";

                foreach ($previousAttempts as $attempt) {
                    $attemptNum = $attempt['attempt'] ?? '?';
                    $confidence = round(($attempt['confidence'] ?? 0) * 100);
                    $status = $attempt['status'] ?? 'unknown';
                    $canonical = $attempt['canonical'] ?? [];
                    $issues = $attempt['issues'] ?? [];

                    $iterationSection .= "Attempt #{$attemptNum}: {$status}, confidence: {$confidence}%\n";

                    if (! empty($canonical['family'])) {
                        $iterationSection .= '  - Identified: '.($canonical['family'] ?? 'unknown');
                        if (! empty($canonical['variant'])) {
                            $iterationSection .= " ({$canonical['variant']})";
                        }
                        $iterationSection .= "\n";
                    }

                    if (! empty($issues)) {
                        $iterationSection .= '  - Issues: '.count($issues)." questions needed clarification\n";
                    }
                }

                $iterationSection .= "\nIMPORTANT: Use information from previous attempts to improve your analysis.\n";
                $iterationSection .= "Focus on resolving issues identified in previous attempts.\n";
            }
        }

        // Build web search section
        $webSearchSection = '';
        if (is_array($webSearchData) && ! empty($webSearchData)) {
            $webSearchSection = "\n\n**WEB SEARCH RESULTS:**\n";
            $webSearchSection .= "Additional information gathered from web search:\n\n";

            foreach (array_slice($webSearchData, 0, 3) as $idx => $searchResult) {
                $query = $searchResult['query'] ?? '';
                $summary = $searchResult['result']['summary'] ?? '';

                // Ensure summary is a string
                if (is_array($summary)) {
                    $summary = json_encode($summary, JSON_UNESCAPED_UNICODE);
                }

                if ($summary) {
                    $webSearchSection .= 'Search #{'.($idx + 1)."}: \"{$query}\"\n";
                    $webSearchSection .= $summary."\n\n";
                }
            }

            $webSearchSection .= "IMPORTANT: Use this web search information to increase confidence and accuracy.\n";
        }

        // Build existing identity section
        $existingIdentitySection = '';
        if (is_array($existingIdentity) && ! empty($existingIdentity)) {
            $existingIdentityJson = json_encode($existingIdentity, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $existingIdentitySection = "\n\n**EXISTING IDENTITY DATA:**\n";
            $existingIdentitySection .= "The following identity information was already captured during exam creation:\n";
            $existingIdentitySection .= "```json\n{$existingIdentityJson}\n```\n";
            $existingIdentitySection .= 'IMPORTANT: Use this as prior information. If document evidence contradicts this, ';
            $existingIdentitySection .= "prefer document evidence but note the conflict in your reasoning.\n";
        }

        // Build user metadata section
        $userMetaSection = '';
        if (is_array($userMeta) && ! empty($userMeta)) {
            $userMetaJson = json_encode($userMeta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $userMetaSection = "\n\n**USER METADATA (from initial analysis):**\n";
            $userMetaSection .= "Additional information about the user and their exam preparation:\n";
            $userMetaSection .= "```json\n{$userMetaJson}\n```\n";
            $userMetaSection .= "IMPORTANT: This metadata can help you understand user's intent and target exam details.\n";
        }

        return <<<PROMPT
**USER INPUT:**
```json
{$userJson}
```

**DOCUMENT TEXT EXTRACT:**
```
{$textSnippet}
```

**MARKER PACK INFO:**
{$markerInfo}
{$existingIdentitySection}
{$userMetaSection}
{$iterationSection}
{$webSearchSection}

**YOUR TASK:**
Analyze the provided information and determine the exam identity with maximum confidence.

1. **Check for direct evidence:**
   - Exam name in document header/footer
   - Provider/publisher name or logo references
   - Official certification body mentions
   - ISBN, publication codes, official URLs

2. **Analyze structural signatures:**
   - Section names and order (Listening/Reading/Writing/Speaking vs. Reading/Listening/Speaking/Writing)
   - Timing patterns (e.g., "30 min + 10 min transfer" is IELTS signature)
   - Question counts per section
   - Task types and their descriptions

3. **Cross-check user input:**
   - Does stated exam_name match document evidence?
   - Does language align with exam family?
   - Does location/modality make sense for this exam?
   - Does target level/score fit the exam's scale?

4. **Assign confidence:**
   - If you have explicit mentions + structural match → 0.90-0.95 "certain"
   - If strong structural match but no explicit name → 0.80-0.89 "certain" if unambiguous
   - If ambiguous or missing key info → "uncertain" with specific followups

5. **Identify what's missing:**
   - If user_input lacks language/location/target → add to need_fields
   - If you can't disambiguate variant (Academic vs General, iBT vs Paper, A2 vs B1) → add specific question to followups

Return ONLY the JSON response matching the schema. Be precise and conservative.
PROMPT;
    }

    /**
     * Returns the JSON schema for this prompt's expected response
     */
    public static function schema(): array
    {
        return JsonSchemaExamIdentity::schema();
    }

    /**
     * Helper: builds complete payload for AI provider
     */
    public static function buildPayload(array $userInput, ?string $extractedText, array $markerPack = [], ?array $existingIdentity = null, ?array $userMeta = null): array
    {
        return [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::system(),
                ],
                [
                    'role' => 'user',
                    'content' => self::userPrompt($userInput, $extractedText, $markerPack, $existingIdentity, $userMeta),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'exam_identity_verdict',
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
        ];
    }
}
