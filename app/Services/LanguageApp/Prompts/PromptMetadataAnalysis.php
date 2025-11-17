<?php

namespace App\Services\LanguageApp\Prompts;

class PromptMetadataAnalysis
{
    /**
     * Build prompt for metadata analysis
     *
     * @param array $sources Available information sources
     * @return string The prompt
     */
    public static function build(array $sources): string
    {
        $sourcesJson = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
You are an AI assistant specialized in analyzing language exam preparation metadata.

Your task is to extract and infer information about:
1. The USER (person taking the exam)
2. The EXAM itself (identity and details)

# Available Information

The following information has been provided:

```json
{$sourcesJson}
```

# Your Task

Analyze all provided information and extract/infer the following:

## User Metadata (user_meta)
- **user_language**: Language the user is writing in (detect from input text patterns, grammar, vocabulary). Use ISO 639-1 code (e.g., "ru", "en", "de", "es").
- **user_gender**: User's gender if inferable from language patterns (e.g., Russian/Polish endings, self-references). Use "male", "female", or "unknown".
- **exam_language**: Language of the exam being prepared for (the TARGET language, not the user's native language). Use ISO 639-1 code.
- **exam_level**: CEFR level mentioned by user (A1, A2, B1, B2, C1, C2) if different from system-selected level.
- **target_score**: Desired score/band/result mentioned by user (e.g., "7.5", "B2", "180", "TDN 4").
- **exam_purpose**: Why the user needs this exam (e.g., "university admission", "work visa", "immigration", "professional certification", "personal development").
- **needs_certificate**: Does the user explicitly mention needing an official certificate? Boolean or null if not mentioned.
- **exam_modality**: How the exam will be taken - "online" (computer-based, remote) or "offline" (paper-based, test center, in-person). Infer from keywords like "онлайн", "online", "computer", "оффлайн", "offline", "школа", "центр", "test center".
- **additional_info**: Any other relevant information about the user's situation, timeline, previous attempts, etc.

## Exam Identity (identity)
- **exam_family**: Official exam family name (e.g., "IELTS", "TOEFL iBT", "Goethe-Zertifikat", "DELF/DALF"). Must match one of the known exam families or "Unknown".
- **exam_name**: Full official name of the specific exam (e.g., "IELTS Academic", "TOEFL iBT", "Goethe-Zertifikat B2").
- **exam_code**: Official exam code if mentioned (e.g., "IELTS", "C1 Advanced").
- **provider**: Organization providing the exam (e.g., "British Council", "IDP Education", "ETS", "Goethe-Institut", "Cambridge Assessment English").
- **country**: Country where exam will be taken or where it's most relevant (e.g., "UK", "USA", "Germany", "France").
- **exam_language**: Language of the exam (redundant with user_meta, but important for identity).

## Confidence
- **confidence**: Your confidence level in the analysis (0.0 to 1.0). Consider:
  - 0.95-1.0: Explicit information provided, no ambiguity
  - 0.80-0.94: Strong inference from clear context
  - 0.60-0.79: Reasonable inference with some assumptions
  - 0.40-0.59: Weak inference, significant uncertainty
  - 0.0-0.39: Very uncertain, mostly guessing

# Important Guidelines

1. **Detect User Language**: Analyze grammar, vocabulary, and writing patterns to detect the user's native language:
   - Russian: Cyrillic script, verb aspects, case endings, "я сдаю", "мне нужно"
   - English: Latin script, articles (the, a), simple verb forms
   - German: compound words, case system, "ich möchte", "Prüfung"
   - Spanish: "¿...?", subjunctive, "necesito", "examen"
   - French: accents (é, è, ê), articles (le, la), "je veux", "examen"

2. **Detect User Gender** (if possible):
   - Russian: verb endings (сдавала/сдавал, хотела/хотел)
   - Polish: past tense endings (zdawałam/zdawałem)
   - Hebrew: verb conjugations
   - If not inferable: "unknown"

3. **Distinguish Native vs Target Language**:
   - If user writes in Russian about "английский экзамен" → user_language: "ru", exam_language: "en"
   - If user writes in English about "German B2 exam" → user_language: "en", exam_language: "de"

4. **Exam Family Matching**:
   - Use exact names: "IELTS", "TOEFL iBT", "Goethe-Zertifikat", "DELF/DALF", "Cambridge English", etc.
   - If unsure: "Unknown"

5. **Confidence Calibration**:
   - Be conservative: only use high confidence (>0.9) when information is explicit
   - Lower confidence when inferring from context
   - Consider consistency across sources (user_input vs description vs existing_identity)

6. **Handle Conflicts**:
   - If existing_identity conflicts with user_input, prefer user_input (it's more recent)
   - Note conflicts in your reasoning

7. **Null vs Unknown**:
   - Use `null` for fields where no information is available
   - Use "unknown" for specific fields where we explicitly don't know (e.g., gender, exam_family)

# Output Format

You MUST respond with a valid JSON object matching this schema:

```json
{
  "user_meta": {
    "user_language": "string (ISO 639-1 code or null)",
    "user_gender": "male|female|unknown",
    "exam_language": "string (ISO 639-1 code or null)",
    "exam_level": "A1|A2|B1|B2|C1|C2 or null",
    "target_score": "string or null",
    "exam_purpose": "string or null",
    "needs_certificate": "boolean or null",
    "exam_modality": "online|offline or null",
    "additional_info": "string or null"
  },
  "identity": {
    "exam_family": "string or null",
    "exam_name": "string or null",
    "exam_code": "string or null",
    "provider": "string or null",
    "country": "string or null",
    "exam_language": "string (ISO 639-1 code or null)"
  },
  "confidence": 0.85,
  "reasoning": "Brief explanation of your analysis and confidence level"
}
```

# Example

Input:
```json
{
  "user_input": "здаю в Варшаве, оффлайн на базе школы. Нужен сертификат C1 польского языка.",
  "exam_title": "C1 pl",
  "exam_level": "C1"
}
```

Output:
```json
{
  "user_meta": {
    "user_language": "ru",
    "user_gender": "unknown",
    "exam_language": "pl",
    "exam_level": "C1",
    "target_score": null,
    "exam_purpose": "certification",
    "needs_certificate": true,
    "exam_modality": "offline",
    "additional_info": "Taking exam in Warsaw, at a school-based test center"
  },
  "identity": {
    "exam_family": "Państwowy Certyfikat Języka Polskiego",
    "exam_name": "Państwowy Certyfikat Języka Polskiego C1",
    "exam_code": null,
    "provider": "Państwowa Komisja ds. Poświadczania Znajomości Języka Polskiego",
    "country": "Poland",
    "exam_language": "pl"
  },
  "confidence": 0.92,
  "reasoning": "User writes in Russian (здаю = сдаю). Explicitly mentions Warsaw (Варшава) → Poland. Mentions 'offline at school' (оффлайн на базе школы) → offline modality. Target language is Polish (польского языка). Level C1 confirmed. High confidence based on explicit location and modality information."
}
```

Now analyze the provided information and respond with your analysis.
PROMPT;
    }
}
