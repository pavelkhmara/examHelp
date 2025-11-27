<?php

namespace App\Services\LanguageApp\Prompts;

class PromptAutoClarification
{
    public static function build(array $identity, array $userInput, ?string $extractedText): string
    {
        $candidates = $identity['candidates'] ?? [];
        $followups = $identity['followups'] ?? [];
        $needFields = $identity['need_fields'] ?? [];

        $candidatesJson = json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $userInputJson = json_encode($userInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $extractedPreview = $extractedText ? mb_substr($extractedText, 0, 2000) : 'No document provided';

        return <<<PROMPT
You are an AI assistant helping to identify an exam when user confirmation is not possible.

## Context
The identity guard has questions that normally require user clarification:
Missing fields: {self::formatArray($needFields)}
Follow-up questions: {self::formatArray($followups)}

## Available Information
User input: {$userInputJson}

Document preview (first 2000 chars):
{$extractedPreview}

## Candidate Exams
{$candidatesJson}

## Task
Based on the available information (user input + document), make the MOST REASONABLE inference about:
1. Which candidate exam is the best match (if multiple)
2. What are the most likely values for missing fields
3. What are the most probable answers to follow-up questions

## Output Format (JSON)
{
  "selected_candidate": {
    "name": "string",
    "reason": "why this candidate was selected"
  },
  "inferred_data": {
    "provider": "inferred provider name or null",
    "variant": "inferred variant or null",
    "modality": "test_center|online|paper|computer",
    "target_score": "inferred target score/level or null",
    ...any other missing fields
  },
  "confidence": 0.0-1.0,
  "reasoning": "explanation of how you made these inferences",
  "disclaimer": "brief statement that this is AI-inferred, not user-confirmed"
}

## Guidelines
- Be conservative - if truly uncertain, indicate lower confidence
- Prefer data from document over generic assumptions
- If multiple candidates have similar scores, choose the most common/popular variant
- Include clear reasoning for your choices
- The disclaimer should be user-friendly and honest

Make your best inference now:
PROMPT;
    }

    private static function formatArray(array $items): string
    {
        if (empty($items)) {
            return 'none';
        }

        return implode("\n- ", array_map(function ($item) {
            if (is_array($item)) {
                return '- '.json_encode($item, JSON_UNESCAPED_UNICODE);
            }

            return "- $item";
        }, $items));
    }
}
