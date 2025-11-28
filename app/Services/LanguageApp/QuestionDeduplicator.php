<?php

declare(strict_types=1);

namespace App\Services\LanguageApp;

use App\Models\Exam;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class QuestionDeduplicator
{
    private const DUPLICATE_THRESHOLD = 0.85;

    /**
     * @param  array<int, array<string, mixed>>  $newQuestions
     * @return array<int, array<string, mixed>>
     */
    public function detectDuplicates(array $newQuestions, Exam $exam): array
    {
        if (empty($newQuestions)) {
            return [];
        }

        $existing = $exam->meta['generated_questions_v2'] ?? [];
        $existingIndex = $this->buildComparisonIndex($existing);

        $processed = [];
        $deduped = [];

        foreach ($newQuestions as $question) {
            $questionText = $this->buildComparableText($question);
            $questionId = $question['id'] ?? null;

            $duplicateData = $this->findDuplicateMatch($questionText, $existingIndex);

            if (! $duplicateData && ! empty($processed)) {
                $duplicateData = $this->findDuplicateMatch($questionText, $processed);
            }

            if ($duplicateData) {
                $question['is_duplicate'] = true;
                $question['duplicate_of'] = $duplicateData['id'];
                $question['similarity_score'] = $duplicateData['score'];
            } else {
                $question['is_duplicate'] = $question['is_duplicate'] ?? false;
                $question['duplicate_of'] = $question['duplicate_of'] ?? null;
                $question['similarity_score'] = $question['similarity_score'] ?? 0.0;
            }

            $deduped[] = $question;

            $processed[] = [
                'id' => $questionId ?? Arr::get($question, 'id', Str::uuid()->toString()),
                'text' => $questionText,
            ];
        }

        return $deduped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array{id: string, text: string}>
     */
    protected function buildComparisonIndex(array $questions): array
    {
        return collect($questions)
            ->map(function ($question) {
                $text = $this->buildComparableText($question);
                $id = Arr::get($question, 'id') ?? Str::uuid()->toString();

                return [
                    'id' => $id,
                    'text' => $text,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $question
     */
    protected function buildComparableText(array $question): string
    {
        $parts = [];

        $stimulus = Arr::get($question, 'stimulus.text_html') ?? '';
        $instructionsBrief = Arr::get($question, 'instructions.brief') ?? '';
        $instructionsFull = Arr::get($question, 'instructions.full') ?? '';

        $parts[] = $stimulus;
        $parts[] = $instructionsBrief;
        $parts[] = $instructionsFull;

        $options = Arr::get($question, 'interaction.options', []);
        if (! is_array($options)) {
            $options = [];
        }

        foreach ($options as $option) {
            if (is_array($option)) {
                $parts[] = $option['label'] ?? '';
            }
        }

        $raw = implode("\n", array_filter($parts));

        return $this->normalizeString($raw);
    }

    /**
     * @param  array<int, array{id: string, text: string}>  $index
     * @return array{id: string, score: float}|null
     */
    protected function findDuplicateMatch(string $text, array $index): ?array
    {
        $bestMatch = null;
        $bestScore = 0.0;

        foreach ($index as $candidate) {
            $score = $this->calculateSimilarity($text, $candidate['text']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        if ($bestMatch && $bestScore >= self::DUPLICATE_THRESHOLD) {
            return [
                'id' => $bestMatch['id'],
                'score' => round($bestScore, 4),
            ];
        }

        return null;
    }

    protected function calculateSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return ((float) $percent) / 100.0;
    }

    protected function normalizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
        $value = mb_strtolower(trim($value));

        return $value;
    }
}
