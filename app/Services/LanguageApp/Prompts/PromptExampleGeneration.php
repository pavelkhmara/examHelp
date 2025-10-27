<?php

namespace App\Services\LanguageApp\Prompts;

class PromptExampleGeneration
{
    public static function build(array $examInfo, array $archetypes, int $examplesPerArchetype = 1): string
    {
        $examName = $examInfo['canonical']['name'] ?? $examInfo['title'] ?? 'Unknown Exam';
        $examLevel = $examInfo['canonical']['variant'] ?? $examInfo['level'] ?? '';
        $examDescription = $examInfo['canonical']['description'] ?? '';

        $archetypesJson = json_encode($archetypes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are an expert educational content creator for exam preparation.

## Exam Information
Name: {$examName}
Level: {$examLevel}
Description: {$examDescription}

## Task
Generate {$examplesPerArchetype} realistic example question(s) for EACH archetype listed below.
Each example must be authentic to the exam format and difficulty level.

## Archetypes
{$archetypesJson}

## Output Format (JSON)
{
  "examples": [
    {
      "archetype_id": "string",           // Must match one of the archetype IDs from above
      "question": "string",               // The actual question text or prompt
      "description": "string|null",       // Brief description of what this question tests
      "duration_minutes": int|null,       // Recommended time to complete (if applicable)
      "instructions": "string|null",      // Instructions for the test-taker
      "example_response": {               // Example of a correct/good response
        "answer": "mixed",                // The actual answer content
        "explanation": "string|null"      // Why this is a good answer
      },
      "assessment_guide": "string|null",  // Guidelines for evaluating responses
      "type": "string",                   // Question type (see below for valid types)
      "payload": { ... }                  // Type-specific data (see required structure below)
    }
  ]
}

## Question Types and Required Payload Structures

**CRITICAL**: You MUST use ONLY the question types listed below. Any other type will cause validation errors.

**VALID QUESTION TYPES (whitelist):**
single_select, multi_select, true_false, yes_no_ng, dropdown_cloze, gap_cloze, banked_cloze, matching, order_sentences, order_words, highlight_text, short_answer, numeric, listen_mcq, dictation, error_correction, writing_prompt, speaking_prompt

**IMPORTANT**: The "payload" field MUST match the exact structure for each type. Invalid payloads will cause validation errors.

### single_select (Multiple choice, one correct answer)
**Required payload structure:**
{
  "options": [
    {"id": "a", "text": "First option"},
    {"id": "b", "text": "Second option"},
    {"id": "c", "text": "Third option"}
  ],
  "answer": "b"  // Must be one of the option ids
}
**Requirements:**
- "options": array with at least 2 items
- Each option MUST have "id" (non-empty string) and "text" (non-empty string)
- "answer": string that matches one of the option ids

### multi_select (Multiple choice, multiple correct answers)
**Required payload structure:**
{
  "options": [
    {"id": "a", "text": "First option"},
    {"id": "b", "text": "Second option"},
    {"id": "c", "text": "Third option"}
  ],
  "answers": ["a", "c"]  // Array of option ids
}
**Requirements:**
- "options": array with at least 2 items (same structure as single_select)
- "answers": non-empty array of strings, each must match an option id

### true_false (True/False questions)
**Required payload structure:**
{
  "answer": "true"  // Must be exactly "true" or "false" (string)
}
**Requirements:**
- "answer": string, must be exactly "true" or "false"

### highlight_text (Highlight specific text spans)
**Required payload structure:**
{
  "spans": [
    {"start": 0, "end": 10},
    {"start": 25, "end": 35}
  ]
}
**Requirements:**
- "spans": array of objects
- Each span MUST have "start" (integer >= 0) and "end" (integer > start)

### order_words / order_sentences (Put items in correct order)
**Required payload structure:**
{
  "order": ["first item", "second item", "third item"]
}
**Requirements:**
- "order": non-empty array of strings

### short_answer (Short text response)
**Optional payload structure:**
{
  "answers": ["correct answer", "alternative answer"]
}
**Requirements:**
- "answers": optional, but if provided must be array of strings (non-empty)

### numeric (Numeric answer)
**Optional payload structure:**
{
  "answers": [42, 42.5]
}
**Requirements:**
- "answers": optional, but if provided must be array of numbers

### Other types (gap_cloze, banked_cloze, dropdown_cloze, matching, dictation, error_correction, writing_prompt, speaking_prompt, listen_mcq, yes_no_ng)
**Payload structure:** Flexible - any valid JSON object is accepted
**Example:**
{
  "rubric": "...",
  "word_count": 250,
  "any_other_fields": "..."
}

## Guidelines
1. **Authenticity**: Examples must match real exam patterns and difficulty
2. **Completeness**: Include all relevant metadata (description, instructions, assessment guide)
3. **Diversity**: If generating multiple examples per archetype, make them diverse
4. **Quality**: Each example should be usable as-is for student practice
5. **Assessment**: Provide clear rubric/guide for evaluation
6. **Realism**: Use realistic content, scenarios, and difficulty appropriate to the level

## Important Notes
- Duration should reflect realistic completion time for that question type
- Instructions should be clear and match exam conventions
- Example responses should demonstrate expected quality
- Assessment guides should help teachers/systems evaluate student work

## CRITICAL: Payload Validation
**MUST FOLLOW**: The "payload" field will be strictly validated. You MUST use the exact structure shown above for each question type.

**Common mistakes to avoid:**
- ❌ Using invalid question types (e.g., "listening_table", "table_completion", etc.) - ONLY use types from the whitelist above
- ❌ Using "correct_answer" instead of "answer" for single_select
- ❌ Using "answer" (singular) instead of "answers" (plural) for multi_select
- ❌ Missing "id" field in options
- ❌ Using boolean true/false instead of string "true"/"false"
- ❌ Empty arrays for required fields
- ❌ Wrong data types (string instead of number, etc.)

**Before generating:** Review the archetype's question type and use the corresponding payload structure exactly as shown above.

Generate the examples now:
PROMPT;
    }
}
