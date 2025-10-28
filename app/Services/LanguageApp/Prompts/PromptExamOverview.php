<?php

namespace App\Services\LanguageApp\Prompts;

class PromptExamOverview
{
    /**
     * Build the exam overview research prompt
     */
    public static function build(
        string $examTitle,
        string $userInput,
        string $contextNotes,
        ?string $retryHint = null,
        ?array $userInputParsed = null,
        string $documentsHint = ''
    ): string {
        $categoryWeightHint = self::getCategoryWeightHint();
        $localizationHint = self::getLocalizationHint($userInputParsed);
        $schemaDescription = self::getSchemaDescription();
        $documentPriorityHint = self::getDocumentPriorityHint($documentsHint);

        return <<<EOT
You are an educational researcher for exam prep.
Information from user about exam: {$userInput}

{$documentPriorityHint}

You must browse the web to discover authentic question patterns for the target exam.
Follow these constraints:
- Use at least 4 reputable sources with diversity (.gov, .edu, official exam sites, major publishers).
- Extract patterns (archetypes), typical distractors, verbs, numeric ranges, units, common visuals, difficulty bands.
- **REQUIRED**: Map EACH task archetype to sections (exam parts like listening, reading, writing, speaking).
- **CRITICAL TIMING PRIORITIES** (in order of importance):
  1. **HIGHEST**: total_exam_duration (entire exam time) - NEVER guess, must be from official sources
  2. **MEDIUM**: section_duration (time per section: Listening, Reading, etc.) - important for scheduling
  3. **LOWEST**: step_duration (time per individual task) - useful but not critical
- **REQUIRED**: Provide timing at ALL levels if available. Search official sources. If sources conflict, use SMALLER value and note alternatives in rationale. NEVER leave total_exam_duration empty.
- Record each source: url, title, publisher
- If evidence conflicts, include both views and explain under rationale.
{$localizationHint}
{$categoryWeightHint}
{$retryHint}

IMPORTANT - Timing Hierarchy:
- **Total Exam Duration**: CRITICAL - sum of all sections (or explicitly stated total time)
- **Section Duration**: IMPORTANT - time allocated to each major part (Listening: 30 min, Reading: 60 min, etc.)
- **Task/Step Duration**: OPTIONAL - time for individual tasks within sections (if available)

PRIORITY ORDER (do not miss higher priorities):
1. total_exam_duration - MUST have from official sources
2. section_duration for each major section - HIGHLY RECOMMENDED
3. step_duration for individual tasks - nice to have but not required

Output strictly the JSON object described in the response_json_schema. If unsure, be conservative.

Task: Mine question archetypes and style for the exam.

exam_name: {$examTitle}
exam_description: {$contextNotes}
timebox_minutes: 2,5

{$documentsHint}

{$schemaDescription}
EOT;
    }

    /**
     * Get document priority hint
     */
    private static function getDocumentPriorityHint(string $documentsHint): string
    {
        if (empty($documentsHint)) {
            return '';
        }

        return <<<'HINT'
**CRITICAL - Document Priority:**
The user has provided exam documents below (past exam papers, official guidelines, sample questions, etc.).
These documents are PRIMARY SOURCES and must take priority over generic web information.

REQUIREMENTS:
1. Read and analyze ALL provided documents carefully
2. Extract timing information with STRICT PRIORITY:
   - PRIORITY 1 (CRITICAL): Total exam duration (e.g., "Total time: 2 hours 45 minutes")
   - PRIORITY 2 (IMPORTANT): Section durations (e.g., "Listening: 30 min", "Reading: 60 min")
   - PRIORITY 3 (OPTIONAL): Task durations (e.g., "Task 1: 15 min", "Task 2: 20 min")
3. Use EXACT timing values from documents (not estimates):
   - Total exam time → use as total_exam_duration
   - Section-level: "Listening: 30 minutes" → use as section_duration
   - Task-level: "Task 1: 15 minutes" → use as step_duration
4. If only section timing given: sum sections to get total_exam_duration
5. Extract exact question formats, sections, criteria from documents
6. If documents show actual exam questions, use them as templates for archetypes
7. List documents in sources with provenance='document'
8. Only use web sources to supplement missing information, not to contradict documents

The documents contain REAL exam materials - treat them as ground truth.
HINT;
    }

    /**
     * Get localization hint for local sources
     */
    private static function getLocalizationHint(?array $userInput): string
    {
        if (! $userInput) {
            return '';
        }

        $language = $userInput['language'] ?? null;
        $country = $userInput['where']['country'] ?? null;

        // Determine which language sources to prioritize
        $targetLanguages = [];

        if ($country) {
            $countryLanguages = self::getCountryLanguages($country);
            if (! empty($countryLanguages)) {
                $targetLanguages = $countryLanguages;
                $hint = "\n**CRITICAL - Local Sources Requirement:**\n";
                $hint .= "You MUST include at least 2 sources in the local language(s) of {$country}: ".implode(' or ', $targetLanguages).".\n";
                $hint .= "Prioritize official government sites, certification bodies, and educational institutions from {$country}.\n";
                $hint .= 'Examples: .gov sites, official exam provider sites, local test preparation centers.';

                return $hint;
            }
        }

        if ($language) {
            $hint = "\n**CRITICAL - Local Sources Requirement:**\n";
            $hint .= "You MUST include at least 2 sources in {$language} language.\n";
            $hint .= "Prioritize official certification bodies, exam providers, and educational institutions in {$language}.\n";
            $hint .= 'Examples: official exam sites, language institutes, government education portals.';

            return $hint;
        }

        return '';
    }

    /**
     * Map country codes to primary languages
     */
    private static function getCountryLanguages(string $countryCode): array
    {
        $map = [
            'PL' => ['Polish', 'polski'],
            'DE' => ['German', 'Deutsch'],
            'FR' => ['French', 'français'],
            'ES' => ['Spanish', 'español'],
            'IT' => ['Italian', 'italiano'],
            'RU' => ['Russian', 'русский'],
            'CN' => ['Chinese', '中文'],
            'JP' => ['Japanese', '日本語'],
            'KR' => ['Korean', '한국어'],
            'BR' => ['Portuguese', 'português'],
            'PT' => ['Portuguese', 'português'],
            'NL' => ['Dutch', 'Nederlands'],
            'SE' => ['Swedish', 'svenska'],
            'NO' => ['Norwegian', 'norsk'],
            'FI' => ['Finnish', 'suomi'],
            'DK' => ['Danish', 'dansk'],
            'CZ' => ['Czech', 'čeština'],
            'GR' => ['Greek', 'ελληνικά'],
            'TR' => ['Turkish', 'Türkçe'],
            'UA' => ['Ukrainian', 'українська'],
        ];

        return $map[strtoupper($countryCode)] ?? [];
    }

    /**
     * Get section weight hint for the prompt
     */
    private static function getCategoryWeightHint(): string
    {
        return <<<'HINT'

CRITICAL REQUIREMENT - Section Distribution:
Each task archetype MUST include a 'category_weights' field mapping it to appropriate exam sections.
Common sections: listening, reading, writing, speaking, grammar, vocabulary.
Example:
{
  "id": "L-MC",
  "name": "Listening multiple choice",
  "category_weights": {
    "listening": 1.0
  }
}

DO NOT leave category_weights empty. DO NOT put all archetypes in "unknown" section.
If uncertain, assign to the most relevant section based on the skill being tested.

NOTE: While the field name is 'category_weights' for backwards compatibility, these represent exam SECTIONS.
HINT;
    }

    /**
     * Get the JSON schema description for the response
     */
    private static function getSchemaDescription(): string
    {
        $schema = self::getSchema();

        return <<<SCHEMA
exam_matrix_json (RESPONSE_JSON_SCHEMA):
{$schema}

This schema defines the expected structure for your response. Follow it strictly.
SCHEMA;
    }

    /**
     * Get the JSON schema for exam overview response
     */
    public static function getSchema(): string
    {
        $schemaArray = [
            'exam_name' => 'string',
            'sources' => [
                [
                    'url' => 'string',
                    'title' => 'string',
                    'publisher' => 'string',
                ],
            ],
            'global_archetypes' => [
                [
                    'id' => 'string (unique identifier for this task archetype)',
                    'name' => 'string (human-readable name)',
                    'stem_templates' => ['string (example question stems)'],
                    'skills_measured' => ['string (skills tested by this archetype)'],
                    'common_distractors' => ['string (typical wrong answer patterns)'],
                    'difficulty_band' => 'string (easy|medium|hard)',
                    'step_duration' => 'number REQUIRED (minutes for THIS TASK - search official sources. If only section timing available, calculate: section_duration/task_count. Use smaller value if sources conflict. NEVER null/empty)',
                    'sequence_matters' => 'boolean (must tasks be done in order?)',
                    'step_order' => 'number|null (position in sequence if sequence_matters)',
                    'category_weights' => [
                        '<section_name>' => 'number (0.0-1.0, weight for this section - sections are exam parts like listening, reading, writing, speaking)',
                    ],
                ],
            ],
            'category_map' => [
                '<section_name>' => [
                    'archetype_weights' => [
                        [
                            'archetype_id' => 'string (reference to archetype.id)',
                            'weight' => 'number (percentage or weight)',
                        ],
                    ],
                ],
            ],
            'total_exam_duration' => 'number REQUIRED (total minutes for entire exam - from official sources)',
            'rationale' => 'string (explanation of your research findings, including sources used for timing and duration calculations)',
        ];

        return json_encode($schemaArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the raw schema array (for programmatic use)
     */
    public static function getSchemaArray(): array
    {
        return [
            'exam_name' => 'string',
            'sources' => [['url' => ['string'], 'title' => ['string'], 'publisher' => ['string']]],
            'global_archetypes' => [
                [
                    'id' => 'string',
                    'name' => 'string',
                    'stem_templates' => ['string'],
                    'skills_measured' => ['string'],
                    'common_distractors' => ['string'],
                    'difficulty_band' => 'medium|hard',
                    'step_duration' => '<minutes>',
                    'sequence_matters' => 'boolean',
                    'step_order' => 'integer|null',
                    'category_weights' => [
                        '<category_name>' => '<weight>',
                    ],
                ],
            ],
            'category_map' => [
                '<category_name>' => [
                    'archetype_weights' => ['archetype_id' => 'string', 'weight' => '<percents>'],
                ],
            ],
            'total_exam_duration' => '<minutes>',
            'rationale' => 'string',
        ];
    }
}
