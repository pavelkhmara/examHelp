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
- **REQUIRED**: Add per-category weights by mapping EACH archetype to categories from the provided exam_matrix.
- **REQUIRED**: For each archetype, provide step_duration (minutes) - search official sources until you find timing information, NEVER leave null.
- Record each source: url, title, publisher
- If evidence conflicts, include both views and explain under rationale.
{$localizationHint}
{$categoryWeightHint}
{$retryHint}

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
2. Extract exact question formats, timing, categories, criteria from documents
3. Use document information to build archetypes (question types, duration, scoring)
4. If documents contain timing information, use EXACT values (not estimates)
5. If documents show actual exam questions, use them as templates for archetypes
6. List documents in sources with provenance='document'
7. Only use web sources to supplement missing information, not to contradict documents

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
     * Get category weight hint for the prompt
     */
    private static function getCategoryWeightHint(): string
    {
        return <<<'HINT'

CRITICAL REQUIREMENT - Category Distribution:
Each archetype MUST include a 'category_weights' field mapping it to appropriate exam sections.
Common categories: listening, reading, writing, speaking, grammar, vocabulary.
Example:
{
  "id": "L-MC",
  "name": "Listening multiple choice",
  "category_weights": {
    "listening": 1.0
  }
}

DO NOT leave category_weights empty. DO NOT put all archetypes in "unknown" category.
If uncertain about category, assign to most relevant section based on skill tested.
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
                    'id' => 'string (unique identifier for this archetype)',
                    'name' => 'string (human-readable name)',
                    'stem_templates' => ['string (example question stems)'],
                    'skills_measured' => ['string (skills tested by this archetype)'],
                    'common_distractors' => ['string (typical wrong answer patterns)'],
                    'difficulty_band' => 'string (easy|medium|hard)',
                    'step_duration' => 'number REQUIRED (minutes for this task - search official sources, NEVER null/empty)',
                    'sequence_matters' => 'boolean (must tasks be done in order?)',
                    'step_order' => 'number|null (position in sequence if sequence_matters)',
                    'category_weights' => [
                        '<category_name>' => 'number (0.0-1.0, weight for this category)',
                    ],
                ],
            ],
            'category_map' => [
                '<category_name>' => [
                    'archetype_weights' => [
                        [
                            'archetype_id' => 'string (reference to archetype.id)',
                            'weight' => 'number (percentage or weight)',
                        ],
                    ],
                ],
            ],
            'total_exam_duration' => 'number REQUIRED (total minutes for entire exam - from official sources)',
            'rationale' => 'string (explanation of your research findings, including sources used for timing)',
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
