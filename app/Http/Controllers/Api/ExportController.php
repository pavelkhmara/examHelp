<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Question;
use Illuminate\Http\JsonResponse;

/**
 * Exports exam data for mobile applications.
 *
 * Output format compatible with Zakazchik's mobile app structure:
 * - data.id, data.name, data.exam_name, etc.
 * - data.categories[] with questions_qty_in_mock
 * - data.questions[] with question, answer, distractor1-4, explanation
 */
class ExportController extends Controller
{
    /**
     * GET /api/exams/{exam}/export
     *
     * Exports exam in mobile app format.
     */
    public function export(Exam $exam): JsonResponse
    {
        $exam->loadMissing(['categories', 'questions.section']);

        $categories = $exam->categories->map(fn (ExamCategory $cat, int $idx) => [
            'id' => $cat->id,
            'app_id' => $exam->id,
            'name' => $cat->name,
            'questions_qty_in_mock' => $cat->questions()->count(),
            'sort_order' => $idx + 1,
        ])->values();

        $questions = $exam->questions()
            ->whereNotNull('interaction')
            ->get()
            ->map(fn (Question $q) => $this->transformQuestion($q, $exam))
            ->filter()
            ->values();

        return response()->json([
            'data' => [
                'id' => $exam->id,
                'name' => $exam->title,
                'exam_name' => $exam->title,
                'exam_full_name' => $exam->meta['full_name'] ?? $exam->title,
                'exam_organization' => $exam->meta['provider'] ?? null,
                'exam_organization_full_name' => $exam->meta['provider_full_name'] ?? null,
                'language' => $exam->language_of_test,
                'level' => $exam->level,
                'categories' => $categories,
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * Transform Question to mobile app format.
     */
    private function transformQuestion(Question $q, Exam $exam): ?array
    {
        $interaction = $q->interaction ?? [];
        $scoring = $q->scoring ?? [];
        $instructions = $q->instructions ?? [];
        $stimulus = $q->getResolvedStimulus();
        $metadata = $q->metadata ?? [];

        // Build question content array
        $questionContent = $this->buildContentArray($instructions, $stimulus);
        if (empty($questionContent)) {
            return null;
        }

        // Build answer and distractors
        $answerData = $this->extractAnswerAndDistractors($q->type, $interaction, $scoring);

        // Build explanation
        $explanation = [];
        if (! empty($scoring['explanation'])) {
            $explanation[] = ['type' => 'text', 'content' => $scoring['explanation']];
        }

        return [
            'id' => $q->id,
            'app_id' => $exam->id,
            'category_id' => $q->section_id,
            'reference' => $q->question_id,
            'difficulty' => $this->mapDifficulty($metadata['difficulty'] ?? null),
            'question' => $questionContent,
            'explanation' => $explanation ?: null,
            'answer' => $answerData['answer'],
            'distractor1' => $answerData['distractor1'] ?? null,
            'distractor2' => $answerData['distractor2'] ?? null,
            'distractor3' => $answerData['distractor3'] ?? null,
        ];
    }

    /**
     * Build content array from instructions and stimulus.
     */
    private function buildContentArray(array $instructions, array $stimulus): array
    {
        $content = [];

        // Add brief/full instructions as text
        $text = $instructions['full'] ?? $instructions['brief'] ?? '';
        if ($text) {
            $content[] = ['type' => 'text', 'content' => $text];
        }

        // Add stimulus text
        if (! empty($stimulus['text_html'])) {
            $content[] = ['type' => 'text', 'content' => strip_tags($stimulus['text_html'])];
        }

        // Add images
        foreach ($stimulus['images'] ?? [] as $img) {
            $url = is_array($img) ? ($img['url'] ?? null) : $img;
            if ($url) {
                $content[] = ['type' => 'image', 'content' => $url];
            }
        }

        // Add audio
        foreach ($stimulus['audio'] ?? [] as $audio) {
            $url = is_array($audio) ? ($audio['url'] ?? null) : $audio;
            if ($url) {
                $content[] = ['type' => 'audio', 'content' => $url];
            }
        }

        return $content;
    }

    /**
     * Extract answer and distractors based on question type.
     */
    private function extractAnswerAndDistractors(string $type, array $interaction, array $scoring): array
    {
        $result = [
            'answer' => [],
            'distractor1' => null,
            'distractor2' => null,
            'distractor3' => null,
        ];

        $options = $interaction['options'] ?? [];
        $answerKey = $scoring['answer_key'] ?? null;

        if (in_array($type, ['single_select', 'multi_select', 'true_false', 'yes_no_ng'])) {
            $correctIdx = null;
            $distractors = [];

            foreach ($options as $idx => $opt) {
                $optId = $opt['id'] ?? $idx;
                $label = $opt['label'] ?? ($opt['text'] ?? '');

                if ($optId === $answerKey || (is_array($answerKey) && in_array($optId, $answerKey))) {
                    $correctIdx = $idx;
                    $result['answer'][] = ['type' => 'text', 'content' => $label];
                } else {
                    $distractors[] = ['type' => 'text', 'content' => $label];
                }
            }

            // Assign distractors
            for ($i = 0; $i < min(3, count($distractors)); $i++) {
                $result['distractor'.($i + 1)] = [$distractors[$i]];
            }
        } elseif (in_array($type, ['fill_blank', 'short_answer'])) {
            // For fill-in-blank, answer_key may be string or array
            $answer = is_array($answerKey) ? implode(', ', $answerKey) : $answerKey;
            $result['answer'][] = ['type' => 'text', 'content' => $answer ?? ''];
        } else {
            // For open-ended types (writing, speaking), use rubric or leave empty
            $result['answer'][] = ['type' => 'text', 'content' => $answerKey ?? ''];
        }

        return $result;
    }

    /**
     * Map difficulty to 1-3 scale.
     */
    private function mapDifficulty(?string $difficulty): int
    {
        return match ($difficulty) {
            'easy' => 1,
            'medium' => 2,
            'hard' => 3,
            default => 2,
        };
    }
}
