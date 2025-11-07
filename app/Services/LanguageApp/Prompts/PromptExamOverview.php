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

**CRITICAL - SOURCE QUALITY REQUIREMENTS:**
Prefer pages that contain the exact answers (explicit timings, task lists, rubrics) even if they are not on .gov/.edu, as long as the information is directly verifiable (official PDF links, scans, or clearly quoted with anchors).
Authority matters, but concrete, checkable evidence wins tie-breaks.


**MINIMUM SOURCE REQUIREMENTS:**
- At least 5 high-quality sources (minimum 4 web + documents if provided)If a primary/official document fully specifies timing + sections, 3 sources are sufficient.
- Ensure diversity: max 2 from the same publisher/domain.
- Include ≥1 primary-evidence page (contains exact timings/sections or direct excerpts of official docs). If none exist, state `no_primary_evidence_found` and pick the highest evidence-weighted sources instead (see below).

**EVIDENCE WEIGHTING (use to choose sources):**
Score each candidate page 0–1 on:
- Specificity (0.4): exact numbers, task names, rubrics, sample items on page.
- Verifiability (0.3): direct quotes with anchors, screenshots, or official PDF links.
- Authority (0.2): official provider/certifying body, .gov/.edu, or accredited test centre.
- Recency (0.1): most recent official version / publication date.

Prefer higher total score. You may select a non-official page over an official one if it scores higher due to direct, verifiable details.

**Authority hints (non-binding):**
Official provider and government portals usually score high on Authority, but do not override lack of Specificity/Verifiability.

**URL QUALITY VALIDATION (CRITICAL):**
Before including any source, VERIFY that the URL:
1. **Does NOT contain error indicators** in the page content:
   - "not found", "404", "page not found"
   - "page doesn't exist", "no longer available"
   - "access denied", "forbidden"
   - "this page has been removed/deleted/moved"
   - "error", "oops", "something went wrong"
2. **Is accessible and contains actual exam information**
3. **Is not a generic landing page or homepage** - must be a specific page about exam structure/format

**EXAMPLES OF GOOD SOURCES (use as reference for quality):**
✓ https://certyfikatpolski.pl/o-egzaminie/struktura-egzaminu/ (Polish Certificate - exam structure)
✓ https://certyfikatpolski.pl/o-egzaminie/kto-moze-zdawac-egzamin/ (Polish Certificate - eligibility)
✓ https://www.ets.org/toefl/test-takers/ibt/about/content.html (TOEFL - official content guide)
✓ https://www.testdaf.de/de/teilnehmende/der-papierbasierte-testdaf/aufbau-des-papierbasierten-testdaf/ (TestDaF - structure)
✓ https://www.france-education-international.fr/diplome/delf-tout-public/niveau-a2 (DELF - level description)

These examples show:
- Official provider domains
- Specific pages about exam structure/format/content
- Direct information about sections, timing, and task types
- Not generic "about us" or landing pages

**DO NOT USE:**
- Generic educational websites without clear attribution
- Commercial prep sites without official credentials
- User-generated content (forums, Reddit, Quora)
- Sites that cannot be verified
- **Broken/error pages** (see validation above)
- **Generic landing pages** without specific exam structure information
- **Outdated pages** that reference deprecated exam versions

**SOURCE CONTRIBUTION TRACKING (CRITICAL):**
For EACH source you use, you MUST specify:
1. What specific information was taken from this source
2. Which archetypes or exam structure elements came from this source
3. How this source improved the research quality
4. Add to the contribution note whether the page provided **direct evidence** (e.g., quoted timing with anchor, official PDF) or **secondary confirmation**.
   In `source_usage.data_types`, include tags like `"direct_quote"`, `"official_pdf"`, or `"screenshot_excerpt"` when applicable.

**FORMAT for source contribution:**
```json
{
  "url": "https://www.ielts.org/for-test-takers/how-ielts-is-scored",
  "title": "How IELTS is Scored - Official Guide",
  "publisher": "IELTS.org (Official)",
  "tier": 1,
  "contribution": "Provided official band score descriptions (0-9 scale), speaking assessment criteria (fluency, lexical resource, grammatical range, pronunciation), and typical score distributions. Used for archetypes: SPK-1, SPK-2, SPK-3.",
  "source_usage": {
    "archetype_ids": ["SPK-1", "SPK-2", "SPK-3"],
    "data_types": ["scoring_criteria", "band_descriptions", "assessment_rubric"]
  }
}
```

**ARCHETYPE SOURCE TRACKING (REQUIRED):**
For EACH global_archetype, you MUST include:
- `source_ids`: array of integers (indices of sources in 'sources' array, 0-based)
- Indicate which sources provided information for this archetype

Example:
```json
{
  "id": "L-MC",
  "name": "Listening Multiple Choice",
  "source_ids": [0, 2, 3],  // This archetype uses sources at indices 0, 2, and 3
  ...
}
```
**SECTION ARCHETYPE MODEL (section_archetypes):**
Provide a section-level blueprint per exam section (listening, reading, grammar_lexis, writing, speaking):
For each section_archetype:
{
  "section": "listening|reading|grammar_lexis|writing|speaking",
  "objectives": ["comprehension gist","detail","inference", ...],
  "skills_subskills": ["note-taking","cohesion","pronunciation-range", ...],
  "allowed_question_types": ["single_select","multi_select","gap_cloze", ...],  // must be from QuestionType enum
  "typical_stimuli": { "text_length": "50–300 words", "audio_repeats": 2, "audio_total_min": "6–12" },
  "item_counts": { "min": 5, "max": 40 },
  "time_guidance_min": { "min": 10, "max": 60 }, // guidance only if official per-task timing absent
  "scoring_focus": ["accuracy","rubric","partial","fuzzy"],
  "common_pitfalls": ["distractor-synonyms","numbers-variants","false-negatives"],
  "constraints": ["no negative marking unless official","show units for numeric"],
  "source_ids": [ ... ] // who informed this section-level blueprint
}
This is type_specific for question_types:
    - single_select|multi_select|true_false|yes_no_ng: options_count_range, distractor_blueprints, lexical_overlap_threshold, partial_rules (для multi)
    - dropdown_cloze|gap_cloze|banked_cloze: slot_count_range, bank_size (для banked), allowed_variants_regex, normalization_rules
    - matching: pair_count_range, shuffle_constraints, partial_scoring_rules
    - order_sentences|order_words: item_count_range, accept_multiple_orders, syntactic_templates
    - highlight_text: span_count_range, span_length_range, tokenization_hint
    - short_answer|numeric: keywords|regex, tolerance|units, normalization
    - listen_mcq|dictation: audio_repeats, audio_length_range, orthography_rules (для dictation)
    - error_correction: mode: free_form|targeted, rubric_refs, allowed_tools
    - writing_prompt|speaking_prompt: length_or_time_targets, rubric_criteria, genre/prompts, attempt_limits

**RESEARCH PROCESS:**
1. If documents are provided → analyze them first (document-first). Treat them as ground truth unless contradicted by a newer official source.
2. Run an evidence-first web search. Shortlist pages with the highest Evidence Weighting score.
3. Use high-authority overviews (provider, .gov/.edu) to confirm structure and resolve ambiguities.
4. On conflicts, prefer the page with direct, verifiable evidence (quotes/artifacts). Record the alternative view in rationale.
5. For each extracted fact, record its source and whether it was direct evidence or a secondary confirmation.

**CONSTRAINTS:**
- Extract patterns at TWO levels:
  (A) section_archetypes (section-level patterns)
  (B) question_archetypes (question-level patterns)
- **CLASSIFY EVERY TASK** into one of our accepted question types (QuestionType enum keys below). 
- Apply global_archetypes at the **question level**: for each question_archetype include `question_type` and a `type_specific` object with fields required for that type.
- Keep section mapping via `category_map` (sections → weights per question_archetype).

**ALLOWED QUESTION TYPES (use exact keys; do not invent):**
single_select, multi_select, true_false, yes_no_ng, dropdown_cloze, gap_cloze, banked_cloze,
matching, order_sentences, order_words, highlight_text, short_answer, numeric,
listen_mcq, dictation, error_correction, writing_prompt, speaking_prompt

**CLASSIFICATION & MAPPING RULES:**
1) For every task, first pick `question_type` from the ALLOWED list.
2) Build/choose an appropriate `question_archetype` (global_archetypes entry) with `question_type` and `type_specific`.
3) Map that archetype to sections via `category_map` (e.g., reading → {L-MC: 0.4, gap_cloze: 0.2, ...}).
4) Ensure `section_archetypes[section].allowed_question_types` includes the types you map for that section; otherwise flag inconsistency.


- **CRITICAL TIMING PRIORITIES** (in order of importance):
  1. **HIGHEST**: total_exam_duration (entire exam time) - NEVER guess, must be from official sources
  2. **MEDIUM**: section_duration (time per section: Listening, Reading, etc.) - important for scheduling
  3. **LOWEST**: step_duration (time per individual task) - useful but not critical
- **REQUIRED**: Provide timing at ALL levels if available. Search official sources. If sources conflict, use SMALLER value and note alternatives in rationale. NEVER leave total_exam_duration empty.
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

### TIMEBOX POLICY (2.5-2.95 minutes)
If time is short, ensure first:
1) Canonical identity + **total_exam_duration** with citation.
2) At least **1 section_archetype** per active section (listening, reading, grammar_lexis, writing, speaking) with key invariants.
3) ≥ **3 question_archetypes** with proper `question_type` (from ALLOWED TYPES) and a non-empty `type_specific`.
Then expand with section timings, thresholds, and additional archetypes.

### QUALITY GATES (hard fail if not met)
- Gate A: `total_exam_duration` is present and cited (prefer official).
- Gate B: Domain diversity: ≤2 sources per domain; ≥4 sources in total (uploaded docs count).
- Gate C: Every entry in `global_archetypes` has ≥1 `source_id`.
- Gate D: If section timings exist, check sum(section_duration) ≈ total (±5 min) or flag inconsistency.
- Gate E: 100% `global_archetypes` MUST include `question_type` ∈ **ALLOWED QUESTION TYPES** and a non-empty `type_specific`.
- Gate F: For every section in `category_map`, there is a corresponding `section_archetype` that lists those question types in `allowed_question_types`.

Output strictly the JSON object described in the response_json_schema. If unsure, be conservative.

Task: Mine question archetypes and style for the exam using HIGH-QUALITY sources and track their usage.

exam_name: {$examTitle}
exam_description: {$contextNotes}
timebox_minutes: 2.5

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
                    'url' => 'string (full URL to source)',
                    'title' => 'string (document/page title)',
                    'publisher' => 'string (organization or website name)',
                    'tier' => 'number (1=official, 2=trusted publisher, 3=supplementary)',
                    'contribution' => 'string REQUIRED (specific information taken from this source, which archetypes it helped create, how it improved research quality)',
                    'source_usage' => [
                        'archetype_ids' => ['string (IDs of archetypes that used this source)'],
                        'data_types' => ['string (types of data: timing, scoring, format, criteria, etc.)'],
                    ],
                ],
            ],
            'section_archetypes' => [
                [
                    'section' => 'string (listening|reading|grammar_lexis|writing|speaking)',
                    'objectives' => ['string (comprehension gist, detail, inference, etc.)'],
                    'skills_subskills' => ['string (note-taking, cohesion, pronunciation-range, etc.)'],
                    'allowed_question_types' => ['string (must be from QuestionType enum: single_select, multi_select, gap_cloze, etc.)'],
                    'typical_stimuli' => [
                        'text_length' => 'string (e.g., 50-300 words)',
                        'audio_repeats' => 'number (e.g., 2)',
                        'audio_total_min' => 'string (e.g., 6-12)',
                    ],
                    'item_counts' => [
                        'min' => 'number',
                        'max' => 'number',
                    ],
                    'time_guidance_min' => [
                        'min' => 'number',
                        'max' => 'number',
                    ],
                    'scoring_focus' => ['string (accuracy, rubric, partial, fuzzy)'],
                    'common_pitfalls' => ['string (distractor-synonyms, numbers-variants, etc.)'],
                    'constraints' => ['string (no negative marking unless official, show units for numeric, etc.)'],
                    'source_ids' => ['number (indices of sources that informed this section blueprint)'],
                ],
            ],
            'global_archetypes' => [
                [
                    'id' => 'string (unique identifier for this task archetype)',
                    'name' => 'string (human-readable name)',
                    'source_ids' => ['number (indices of sources in sources array that provided info for this archetype - 0-based indices) REQUIRED'],
                    'question_type' => 'string REQUIRED (one of QuestionType enum keys: single_select, multi_select, true_false, yes_no_ng, dropdown_cloze, gap_cloze, banked_cloze, matching, order_sentences, order_words, highlight_text, short_answer, numeric, listen_mcq, dictation, error_correction, writing_prompt, speaking_prompt)',
                    'type_specific' => 'object REQUIRED (object tailored to question_type with required fields for that type)',
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
            'rationale' => 'string (explanation of your research findings, including sources used for timing and duration calculations, source quality assessment)',
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
            'sources' => [[
                'url' => ['string'],
                'title' => ['string'],
                'publisher' => ['string'],
                'tier' => ['number'],
                'contribution' => ['string'],
                'source_usage' => [
                    'archetype_ids' => [['string']],
                    'data_types' => [['string']],
                ],
            ]],
            'section_archetypes' => [
                [
                    'section' => 'string',
                    'objectives' => [['string']],
                    'skills_subskills' => [['string']],
                    'allowed_question_types' => [['string']],
                    'typical_stimuli' => ['object'],
                    'item_counts' => ['object'],
                    'time_guidance_min' => ['object'],
                    'scoring_focus' => [['string']],
                    'common_pitfalls' => [['string']],
                    'constraints' => [['string']],
                    'source_ids' => [['number']],
                ],
            ],
            'global_archetypes' => [
                [
                    'id' => 'string',
                    'name' => 'string',
                    'source_ids' => ['number'],
                    'question_type' => 'string REQUIRED',
                    'type_specific' => 'object REQUIRED',
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
