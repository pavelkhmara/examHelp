<?php

namespace App\Services\LanguageApp\Prompts;

/**
 * Question Synthesis Prompt (Stage 6)
 *
 * Generates individual exam questions based on question archetypes from Phase B.
 * Uses priority system and end-of-prompt checklist for quality.
 */
class PromptQuestionSynthesis
{
    /**
     * Build prompt for question generation
     *
     * @param  string  $questionType  Type of question (single_select, essay, etc.)
     * @param  array  $archetypeConfig  Config from Phase B archetype
     * @param  string  $sectionSkill  Skill being tested (listening, reading, etc.)
     * @param  string  $examLanguage  Target language (e.g., "English")
     * @param  string  $examLevel  CEFR level (A1-C2)
     * @param  int  $quantity  Number of questions to generate
     * @param  array|null  $filters  Optional filters (difficulty, topic, etc.)
     * @param  string|null  $contextHint  Optional context/theme hint
     */
    public static function build(
        string $questionType,
        array $archetypeConfig,
        string $sectionSkill,
        string $examLanguage,
        string $examLevel,
        int $quantity = 1,
        ?array $filters = null,
        ?string $contextHint = null
    ): string {
        $priorityHints = self::getPriorityHints($examLanguage);
        $typeSpecificRequirements = self::getTypeSpecificRequirements($questionType);
        $schemaDescription = self::getSchemaDescription();
        $checklist = self::getChecklist($questionType, $quantity, $examLanguage);

        $archetypeJson = json_encode($archetypeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filtersDescription = $filters ? self::formatFilters($filters) : 'No specific filters';
        $contextDescription = $contextHint ?: 'No specific context provided';

        return <<<EOT
# Role and Objective
You are an expert exam question generator.

**Your task:** Generate **{$quantity}** exam question(s) of type **{$questionType}** for a **{$examLanguage}** language exam at **{$examLevel}** level.

**Section skill:** {$sectionSkill}
**Filters:** {$filtersDescription}
**Context hint:** {$contextDescription}

# Archetype Configuration (from Phase B)
The question must conform to this archetype structure:
```json
{$archetypeJson}
```

{$priorityHints}

{$typeSpecificRequirements}

{$schemaDescription}

# Output Format
Return a **valid JSON array** containing {$quantity} question object(s).

Example structure (for single_select):
```json
[
  {
    "id": "q1",
    "version": "2.0",
    "type": "single_select",
    "skills_measured": ["reading_comprehension"],
    "time_limit_sec": 60,
    "instructions": {
      "brief": "Choose the correct answer",
      "full": "Read the text and select the best answer to the question below."
    },
    "stimulus": {
      "text_html": "<p>Your stimulus text here...</p>"
    },
    "interaction": {
      "response_type": "selection",
      "options": [
        {"id": "opt1", "text": "Option A"},
        {"id": "opt2", "text": "Option B"},
        {"id": "opt3", "text": "Option C"},
        {"id": "opt4", "text": "Option D"}
      ]
    },
    "response": {
      "mode": "selection"
    },
    "scoring": {
      "method": "keyed",
      "answer_key": ["opt2"],
      "max_score": 1
    },
    "metadata": {
      "cefr": ["{$examLevel}"],
      "difficulty": "medium",
      "topic": "general",
      "language": "{$examLanguage}"
    }
  }
]
```

{$checklist}

# Critical Reminders
- Output MUST be valid JSON array (even for single question: `[{...}]`)
- All required fields MUST be present
- Follow the archetype config structure exactly
- **ALL user-facing content (instructions, questions, answer options, stimulus text) MUST be in {$examLanguage} language**
- Ensure logical consistency (e.g., answer_key must reference valid option IDs)
- Use appropriate difficulty level for {$examLevel}
- Questions must be realistic and relevant to {$sectionSkill} skill

EOT;
    }

    /**
     * Get priority hints (CRITICAL > IMPORTANT > DESIRABLE)
     */
    private static function getPriorityHints(string $examLanguage): string
    {
        return <<<HINTS
# Priority Requirements (from critical to desirable)

1. **CRITICAL (MUST)** - violation will break validation:
   - Valid JSON syntax without errors
   - All required fields present:
     * id, version, type, skills_measured, time_limit_sec
     * instructions (brief, full)
     * stimulus (at least one content type)
     * interaction (response_type + relevant fields)
     * response (mode)
     * scoring (method, answer_key/rubric, max_score)
     * metadata (cefr, difficulty, topic, language)
   - Data types match schema (string, integer, array, object)
   - **LANGUAGE REQUIREMENT**: ALL user-facing content MUST be in exam language ({$examLanguage}):
     * instructions.brief and instructions.full
     * stimulus.text_html (questions, passages, prompts)
     * interaction.options[].text (answer choices)
     * Any other text that test-taker will see
     * Exception: Technical fields (id, type, metadata) can be in English
   - Logical consistency:
     * interaction.response_type ↔ response.mode
     * scoring.method ↔ answer_key/rubric presence
     * answer_key references valid option IDs (for selection types)

2. **IMPORTANT (SHOULD)** - highly recommended:
   - Realistic and engaging content
   - Appropriate difficulty for target CEFR level
   - Clear and unambiguous instructions
   - Distractors (wrong answers) are plausible but clearly incorrect
   - Stimulus provides sufficient context
   - Time limit is reasonable for question complexity

3. **DESIRABLE (NICE TO HAVE)** - quality enhancements:
   - Rich metadata (tags, sources)
   - UI hints for better presentation
   - Accessibility features
   - Additional context in stimulus
HINTS;
    }

    /**
     * Get type-specific requirements for question generation
     */
    private static function getTypeSpecificRequirements(string $type): string
    {
        $requirements = [
            'single_select' => <<<'REQ'
# Type-Specific Requirements: single_select
- Must have 3-5 options in interaction.options[]
- Each option needs unique id and text
- scoring.answer_key must contain exactly 1 correct option ID
- Distractors should be plausible but clearly incorrect
- interaction.response_type = "selection"
- response.mode = "selection"
REQ,
            'multi_select' => <<<'REQ'
# Type-Specific Requirements: multi_select
- Must have 4-8 options in interaction.options[]
- Each option needs unique id and text
- scoring.answer_key must contain 2+ correct option IDs
- interaction.response_type = "selection"
- response.mode = "selection"
- Clearly indicate how many answers to select in instructions
REQ,
            'true_false' => <<<'REQ'
# Type-Specific Requirements: true_false
- Must have exactly 2 options: True and False
- scoring.answer_key contains 1 correct option ID
- interaction.response_type = "selection"
- response.mode = "selection"
REQ,
            'essay' => <<<'REQ'
# Type-Specific Requirements: essay
- No options needed (interaction.options can be null)
- scoring.method = "rubric" or "hybrid"
- scoring.rubric must have criteria[] with name, weight, levels
- response.mode = "text"
- response.max_words should be specified
- instructions.full must clearly explain expectations
REQ,
            'short_answer' => <<<'REQ'
# Type-Specific Requirements: short_answer
- No options needed
- scoring.method = "keyed" or "partial"
- scoring.answer_key contains acceptable answers
- response.mode = "text"
- response.max_words typically 10-50
REQ,
            'matching' => <<<'REQ'
# Type-Specific Requirements: matching
- interaction.pairs[] must contain prompt-target pairs
- Each pair has id, prompt_text, target_text
- scoring.answer_key maps prompt IDs to correct target IDs
- response.mode = "matching"
- interaction.response_type = "matching"
REQ,
            'listen_mcq' => <<<'REQ'
# Type-Specific Requirements: listen_mcq (Listening Multiple Choice)
- Must have 3-5 options in interaction.options[]
- scoring.answer_key must contain exactly 1 correct option ID
- interaction.response_type = "selection"
- response.mode = "selection"
- **CRITICAL - Audio Stimulus Requirements:**
  * stimulus.text_html MUST contain the ACTUAL SPOKEN TEXT (dialogue, monologue, announcement)
  * Generate realistic conversation or speech that would be read aloud
  * DO NOT write meta-descriptions like "Fragment dyskusji o..." or "Nagranie zawiera..."
  * DO NOT include prefixes like "Nagranie:", "Audio:", "Wysłuchaj:"
  * DO NOT include playback instructions like "(Kliknij ▶, aby odtworzyć.)"
  * Format as direct speech with speaker labels if dialogue (e.g., "Ekspert 1: ...\nProfesor: ...")
  * For monologues, provide the full spoken text as paragraphs
  * Text should be what test-taker would HEAR, not what they would READ ABOUT the audio
- Example CORRECT format for dialogue:
  ```
  Dziennikarz: Witam państwa w dzisiejszym programie. Naszym gościem jest profesor Jan Kowalski...
  Profesor: Dziękuję za zaproszenie. Chciałbym omówić kwestię...
  ```
- Example INCORRECT format (meta-description):
  ```
  Nagranie: Fragment wywiadu radiowego, w którym dziennikarz rozmawia z profesorem o...
  ```
REQ,
            'dictation' => <<<'REQ'
# Type-Specific Requirements: dictation
- scoring.method = "keyed" or "partial"
- scoring.answer_key contains the exact text to be dictated
- response.mode = "text"
- response.max_words should match expected dictation length
- **CRITICAL - Audio Stimulus Requirements:**
  * stimulus.text_html MUST contain the ACTUAL TEXT TO BE DICTATED
  * This is the exact sentence/paragraph that would be read aloud
  * DO NOT write meta-descriptions like "Zdanie do dyktowania:" or "Tekst zawiera..."
  * DO NOT include prefixes like "Nagranie:", "Dyktando:", "Usłyszysz:"
  * DO NOT include playback instructions
  * Provide only the raw text that would be spoken
- Example CORRECT format:
  ```
  W dzisiejszych czasach technologia odgrywa coraz większą rolę w naszym codziennym życiu.
  ```
- Example INCORRECT format:
  ```
  Nagranie: Zdanie opisujące rolę technologii w życiu codziennym. (Kliknij ▶, aby odtworzyć.)
  ```
REQ,
        ];

        return $requirements[$type] ?? "# Type-Specific Requirements: {$type}\nFollow general schema requirements for this type.";
    }

    /**
     * Get JSON schema description
     */
    private static function getSchemaDescription(): string
    {
        return <<<'SCHEMA'
# Response Schema (s2_question_json_archetype_v2.json)
```json
{
  "id": "string (unique question ID, e.g., 'q1', 'q2')",
  "version": "string (schema version, default: '2.0')",
  "type": "string (question type: single_select, multi_select, essay, etc.)",
  "skills_measured": ["string (skills tested, e.g., 'reading_comprehension', 'listening')"],
  "time_limit_sec": "integer (recommended time in seconds, min: 10)",
  "instructions": {
    "brief": "string (short instruction, 1-2 sentences)",
    "full": "string (detailed instructions)",
    "audio_url": "string (optional - audio instructions)"
  },
  "stimulus": {
    "text_html": "string (optional - HTML text stimulus)",
    "images": ["object (optional - image resources)"],
    "audio": ["object (optional - audio resources)"]
  },
  "interaction": {
    "response_type": "string (selection, text, matching, ordering, spans)",
    "options": ["object (for selection types: {id, text})"],
    "pairs": ["object (for matching: {id, prompt_text, target_text})"],
    "bank": ["string (for cloze: word bank)"],
    "spans": ["object (for text highlighting)"]
  },
  "response": {
    "mode": "string (selection, text, audio, numeric, matching, ordering, spans)",
    "max_words": "integer (optional - for text responses)",
    "recording_limit_sec": "integer (required for audio responses)",
    "attempts": "integer (optional - max attempts allowed)",
    "validation": "object (optional - real-time validation rules)"
  },
  "scoring": {
    "method": "string (keyed, partial, rubric, hybrid)",
    "answer_key": ["mixed (for keyed/partial: correct answers)"],
    "partial_rules": ["object (optional - for partial credit)"],
    "rubric": {
      "criteria": ["object (for rubric scoring: {name, weight, levels})"]
    },
    "max_score": "number (maximum achievable score)"
  },
  "metadata": {
    "cefr": ["string (CEFR levels: A1, A2, B1, B2, C1, C2)"],
    "difficulty": "string (easy, medium, hard)",
    "topic": "string (topic/domain)",
    "language": "string (target language)",
    "sources": ["object (optional - content sources)"],
    "tags": ["string (optional - classification tags)"]
  }
}
```
SCHEMA;
    }

    /**
     * Get end-of-prompt checklist for self-verification
     */
    private static function getChecklist(string $type, int $quantity, string $examLanguage): string
    {
        $plural = $quantity > 1 ? 's' : '';
        return <<<EOT
# Self-Verification Checklist (Review before submitting)
Before returning your response, verify:

✓ **JSON Validity**
  - Valid JSON syntax (no trailing commas, proper quotes)
  - Response is JSON array: `[{...}]` (even for single question)
  - {$quantity} question object{$plural} in array

✓ **Required Fields**
  - All CRITICAL fields present (id, type, skills_measured, etc.)
  - instructions.brief AND instructions.full both provided
  - At least one stimulus type (text_html, images, or audio)
  - interaction.response_type matches question type
  - response.mode is compatible with response_type
  - scoring.method is appropriate for question type
  - metadata.cefr is non-empty array

✓ **Logical Consistency**
  - For selection types: options[] provided, answer_key references valid option IDs
  - For rubric scoring: criteria[] with name/weight/levels provided
  - For audio responses: recording_limit_sec specified
  - time_limit_sec is reasonable (minimum 10 seconds)
  - max_score is positive number

✓ **Content Quality**
  - Question is clear and unambiguous
  - Stimulus provides sufficient context
  - Difficulty appropriate for target level
  - Instructions explain what to do
  - For selection types: distractors are plausible
  - **ALL user-facing text is in {$examLanguage} language (not English unless exam is in English)**

✓ **Type-Specific Requirements**
  - Follows all requirements for '{$type}' question type
  - Archetype config structure respected

If any check fails, revise before submitting.
EOT;
    }

    /**
     * Format filters for display
     */
    private static function formatFilters(array $filters): string
    {
        $parts = [];
        if (isset($filters['difficulty'])) {
            $parts[] = "Difficulty: {$filters['difficulty']}";
        }
        if (isset($filters['topic'])) {
            $parts[] = "Topic: {$filters['topic']}";
        }
        if (isset($filters['tags']) && is_array($filters['tags'])) {
            $parts[] = 'Tags: '.implode(', ', $filters['tags']);
        }

        return $parts ? implode(', ', $parts) : 'No specific filters';
    }
}
