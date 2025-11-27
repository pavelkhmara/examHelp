<?php

namespace App\Nova;

use App\Domain\Taxonomy\QuestionType;
use App\Nova\Fields\CollapsiblePanel;
use App\Nova\Filters\ExamFilter;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ExamExampleQuestion extends Resource
{
    public static $model = \App\Models\ExamExampleQuestion::class;

    public static $title = 'question';

    public static $search = ['id', 'question'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            // Index view fields
            ID::make()->sortable()->onlyOnIndex(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->onlyOnIndex(),
            BelongsTo::make('Category', 'category', ExamCategory::class)
                ->searchable()
                ->onlyOnIndex(),
            Badge::make('Type', 'type')
                ->map([
                    QuestionType::SINGLE_SELECT->value => 'info',
                    QuestionType::MULTI_SELECT->value => 'info',
                    QuestionType::TRUE_FALSE->value => 'success',
                    QuestionType::YES_NO_NG->value => 'success',
                    QuestionType::DROPDOWN_CLOZE->value => 'warning',
                    QuestionType::GAP_CLOZE->value => 'warning',
                    QuestionType::BANKED_CLOZE->value => 'warning',
                    QuestionType::MATCHING->value => 'info',
                    QuestionType::ORDER_SENTENCES->value => 'info',
                    QuestionType::ORDER_WORDS->value => 'info',
                    QuestionType::HIGHLIGHT_TEXT->value => 'info',
                    QuestionType::SHORT_ANSWER->value => 'warning',
                    QuestionType::NUMERIC->value => 'success',
                    QuestionType::LISTEN_MCQ->value => 'info',
                    QuestionType::DICTATION->value => 'warning',
                    QuestionType::ERROR_CORRECTION->value => 'danger',
                    QuestionType::WRITING_PROMPT->value => 'danger',
                    QuestionType::SPEAKING_PROMPT->value => 'danger',
                ])
                ->labels([
                    QuestionType::SINGLE_SELECT->value => 'Single',
                    QuestionType::MULTI_SELECT->value => 'Multi',
                    QuestionType::TRUE_FALSE->value => 'T/F',
                    QuestionType::YES_NO_NG->value => 'Yes/No/NG',
                    QuestionType::DROPDOWN_CLOZE->value => 'Dropdown Cloze',
                    QuestionType::GAP_CLOZE->value => 'Gap Cloze',
                    QuestionType::BANKED_CLOZE->value => 'Banked Cloze',
                    QuestionType::MATCHING->value => 'Matching',
                    QuestionType::ORDER_SENTENCES->value => 'Order Sentences',
                    QuestionType::ORDER_WORDS->value => 'Order Words',
                    QuestionType::HIGHLIGHT_TEXT->value => 'Highlight',
                    QuestionType::SHORT_ANSWER->value => 'Short Answer',
                    QuestionType::NUMERIC->value => 'Numeric',
                    QuestionType::LISTEN_MCQ->value => 'Listen MCQ',
                    QuestionType::DICTATION->value => 'Dictation',
                    QuestionType::ERROR_CORRECTION->value => 'Error Correction',
                    QuestionType::WRITING_PROMPT->value => 'Writing',
                    QuestionType::SPEAKING_PROMPT->value => 'Speaking',
                ])
                ->onlyOnIndex(),
            Text::make('Question Preview', function () {
                return mb_substr($this->question, 0, 100).(mb_strlen($this->question) > 100 ? '...' : '');
            })->onlyOnIndex(),

            // Detail view - beautiful document-like display
            CollapsiblePanel::make('📖 Human-Readable View', 'human_readable_html')
                ->heading('📖 Human-Readable View')
                ->content($this->buildHumanReadableHtml())
                ->expanded()
                ->onlyOnDetail(),
            new Panel('📋 Question Overview', $this->getOverviewFields()),
            new Panel('📝 Question Content', $this->getContentFields()),
            new Panel('✅ Example Response & Assessment', $this->getAssessmentFields()),
            new Panel('🎨 Media Content', $this->getMediaFields()),
            new Panel('🔧 Technical Details', $this->getTechnicalFields()),
        ];
    }

    private function getOverviewFields(): array
    {
        return [
            Text::make('Type', function () {
                return $this->type?->value ?? 'Unknown';
            })->onlyOnDetail(),

            BelongsTo::make('Category', 'category', ExamCategory::class)
                ->onlyOnDetail(),

            Number::make('Duration (minutes)', 'duration_minutes')
                ->onlyOnDetail()
                ->nullable()
                ->help('Recommended time to complete this question'),

            Textarea::make('Description', 'description')
                ->onlyOnDetail()
                ->readonly()
                ->rows(2)
                ->nullable()
                ->help('What this question tests'),
        ];
    }

    private function getContentFields(): array
    {
        return [
            Heading::make('Instructions'),
            Textarea::make('Instructions', 'instructions')
                ->onlyOnDetail()
                ->readonly()
                ->rows(4)
                ->nullable()
                ->withMeta(['extraAttributes' => [
                    'style' => 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.6;',
                ]]),

            Heading::make('Question'),
            Textarea::make('Question Text', 'question')
                ->onlyOnDetail()
                ->readonly()
                ->rows(8)
                ->withMeta(['extraAttributes' => [
                    'style' => 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 15px; line-height: 1.8; font-weight: 500;',
                ]]),
        ];
    }

    private function getAssessmentFields(): array
    {
        return [
            Heading::make('Example Response'),
            Code::make('Example Response', 'example_response')
                ->json()
                ->onlyOnDetail()
                ->nullable()
                ->help('Example of a correct/good response'),

            Heading::make('Assessment Guide'),
            Textarea::make('Assessment Guide', 'assessment_guide')
                ->onlyOnDetail()
                ->readonly()
                ->rows(6)
                ->nullable()
                ->withMeta(['extraAttributes' => [
                    'style' => 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.6; background-color: #f9fafb;',
                ]])
                ->help('Guidelines for evaluating student responses'),
        ];
    }

    private function getMediaFields(): array
    {
        $fields = [];

        // Show image if available
        if ($this->image_url || $this->image_file_path) {
            $fields[] = Heading::make('Generated Image');

            // Display winner image
            $imageUrl = $this->image_url;
            if ($imageUrl) {
                $metadata = $this->image_metadata ?? [];
                $aiScore = $metadata['ai_score'] ?? 'N/A';
                $aiReason = $metadata['ai_reason'] ?? '';
                $provider = $metadata['provider'] ?? 'unknown';
                $author = $metadata['author'] ?? 'Unknown';
                $authorUrl = $metadata['author_url'] ?? '';
                $license = $metadata['license'] ?? '';

                $html = '<div class="space-y-4">';

                // Winner image
                $html .= '<div class="border rounded-lg p-4 bg-white dark:bg-gray-800">';
                $html .= '<h4 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">🏆 Selected Image</h4>';
                $html .= '<img src="'.htmlspecialchars($imageUrl).'" alt="Question image" class="w-full rounded-lg shadow-lg mb-3" style="max-width: 600px;">';

                // Metadata
                $html .= '<div class="grid grid-cols-2 gap-2 text-sm">';
                $html .= '<div class="text-gray-600 dark:text-gray-400">AI Score:</div>';
                $html .= '<div class="font-semibold text-gray-900 dark:text-gray-100">'.htmlspecialchars((string) $aiScore).'/10</div>';
                $html .= '<div class="text-gray-600 dark:text-gray-400">Provider:</div>';
                $html .= '<div class="text-gray-900 dark:text-gray-100">'.htmlspecialchars($provider).'</div>';
                $html .= '<div class="text-gray-600 dark:text-gray-400">Author:</div>';
                $html .= '<div class="text-gray-900 dark:text-gray-100">';
                if ($authorUrl) {
                    $html .= '<a href="'.htmlspecialchars($authorUrl).'" target="_blank" class="text-blue-600 hover:underline">'.htmlspecialchars($author).'</a>';
                } else {
                    $html .= htmlspecialchars($author);
                }
                $html .= '</div>';
                if ($license) {
                    $html .= '<div class="text-gray-600 dark:text-gray-400">License:</div>';
                    $html .= '<div class="text-gray-900 dark:text-gray-100">'.htmlspecialchars($license).'</div>';
                }
                $html .= '</div>';

                if ($aiReason) {
                    $html .= '<div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded">';
                    $html .= '<p class="text-sm text-gray-700 dark:text-gray-300"><strong>AI Reasoning:</strong> '.htmlspecialchars($aiReason).'</p>';
                    $html .= '</div>';
                }

                $html .= '</div>';

                // Alternative images (test mode)
                $alternatives = $this->image_alternatives ?? [];
                if (! empty($alternatives)) {
                    $html .= '<div class="border rounded-lg p-4 bg-gray-50 dark:bg-gray-900/50 mt-4">';
                    $html .= '<h4 class="text-lg font-semibold mb-3 text-gray-900 dark:text-gray-100">🥈 Alternative Options (Test Mode)</h4>';
                    $html .= '<div class="grid grid-cols-2 gap-4">';

                    foreach ($alternatives as $idx => $alt) {
                        $altUrl = $alt['url'] ?? $alt['thumb_url'] ?? '';
                        $altScore = $alt['ai_score'] ?? 'N/A';
                        $altReason = $alt['ai_reason'] ?? '';

                        $html .= '<div class="border rounded p-2 bg-white dark:bg-gray-800">';
                        $html .= '<img src="'.htmlspecialchars($altUrl).'" alt="Alternative '.($idx + 1).'" class="w-full rounded mb-2">';
                        $html .= '<div class="text-xs text-gray-600 dark:text-gray-400">';
                        $html .= '<div><strong>Score:</strong> '.htmlspecialchars((string) $altScore).'/10</div>';
                        if ($altReason) {
                            $html .= '<div class="mt-1">'.htmlspecialchars(mb_substr($altReason, 0, 80)).'...</div>';
                        }
                        $html .= '</div>';
                        $html .= '</div>';
                    }

                    $html .= '</div>';
                    $html .= '</div>';
                }

                $html .= '</div>';

                $fields[] = Text::make('Image', function () use ($html) {
                    return $html;
                })->asHtml()->onlyOnDetail();
            }

            // Show metadata
            if ($this->image_metadata) {
                $fields[] = Code::make('Image Metadata', 'image_metadata')
                    ->json()
                    ->onlyOnDetail()
                    ->nullable()
                    ->help('AI selection metadata and image source information');
            }
        } else {
            $fields[] = Text::make('No Image', function () {
                return '<div class="text-gray-500 dark:text-gray-400 italic">No image generated for this question</div>';
            })->asHtml()->onlyOnDetail();
        }

        return $fields;
    }

    private function getTechnicalFields(): array
    {
        return [
            Code::make('Payload', 'payload')
                ->json()
                ->onlyOnDetail()
                ->nullable()
                ->help('Type-specific data (options, correct answers, etc.)'),

            BelongsTo::make('Exam', 'exam', Exam::class)
                ->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new ExamFilter,
        ];
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        if ($examId = $request->get('exam')) {
            $query->where('exam_id', $examId);
        }

        return $query;
    }

    /**
     * Build human-readable HTML view for question
     */
    private function buildHumanReadableHtml(): string
    {
        $html = '<div class="space-y-6 font-sans">';

        // 1. Название (Question Title/Name)
        $html .= '<div>';
        $html .= '<h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">';
        $html .= htmlspecialchars($this->question_name ?? 'Unnamed Question');
        $html .= '</h3>';
        if ($this->type) {
            $html .= '<span class="inline-block bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 text-sm font-medium px-3 py-1 rounded">';
            $html .= '<p class="text-base text-gray-800 dark:text-gray-200"> Question Type: <b>'.nl2br(htmlspecialchars($this->type->value)).'</b></p>';
            $html .= '</span>';
        }
        $html .= '</div>';

        // 2. Описание (What this tests)
        if ($this->description) {
            $html .= '<div class="border-l-4 border-blue-500 pl-4">';
            $html .= '<h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase mb-2">What This Tests</h4>';
            $html .= '<p class="text-base text-gray-800 dark:text-gray-200">'.nl2br(htmlspecialchars($this->description)).'</p>';
            $html .= '</div>';
        }

        // 3. Условия (Instructions/Conditions)
        if ($this->instructions) {
            $html .= '<div class="bg-yellow-50 dark:bg-yellow-900/10 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">';
            $html .= '<h4 class="text-sm font-semibold text-yellow-900 dark:text-yellow-200 uppercase mb-2">📋 Instructions</h4>';
            $html .= '<p class="text-base text-yellow-900 dark:text-yellow-100">'.nl2br(htmlspecialchars($this->instructions)).'</p>';
            $html .= '</div>';
        }

        // 4. Важные детали (Important Details - from payload or other metadata)
        $importantDetails = $this->getImportantDetails();
        if (! empty($importantDetails)) {
            $html .= '<div class="bg-purple-50 dark:bg-purple-900/10 border border-purple-200 dark:border-purple-800 rounded-lg p-4">';
            $html .= '<h4 class="text-sm font-semibold text-purple-900 dark:text-purple-200 uppercase mb-2">⚠️ Important Details</h4>';
            $html .= '<ul class="list-disc list-inside space-y-1 text-base text-purple-900 dark:text-purple-100">';
            foreach ($importantDetails as $detail) {
                $html .= '<li>'.htmlspecialchars($detail).'</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // 5. Что/как проверяет (Assessment Guide)
        if ($this->assessment_guide) {
            $html .= '<div class="bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800 rounded-lg p-4">';
            $html .= '<h4 class="text-sm font-semibold text-green-900 dark:text-green-200 uppercase mb-2">✅ How to Assess</h4>';
            $html .= '<p class="text-base text-green-900 dark:text-green-100">'.nl2br(htmlspecialchars($this->assessment_guide)).'</p>';
            $html .= '</div>';
        }

        // 6. Тело задания (Question Body - actual exam-like presentation)
        $html .= '<div class="bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-600 rounded-lg p-6 shadow-sm">';
        $html .= '<h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3">📝 Question Body (as in real exam)</h4>';

        // Question text
        $html .= '<div class="mb-4 text-lg leading-relaxed text-gray-900 dark:text-gray-100">';
        $html .= nl2br(htmlspecialchars($this->question));
        $html .= '</div>';

        // Options/Variants (if applicable from payload)
        $questionBody = $this->buildQuestionBody();
        if ($questionBody) {
            $html .= $questionBody;
        }

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Extract important details from question payload
     */
    private function getImportantDetails(): array
    {
        $details = [];

        if ($this->duration_minutes) {
            $details[] = "Duration: {$this->duration_minutes} minutes";
        }

        $payload = $this->payload ?? [];

        if (isset($payload['max_score'])) {
            $details[] = "Max Score: {$payload['max_score']}";
        }

        if (isset($payload['word_limit'])) {
            $details[] = "Word Limit: {$payload['word_limit']}";
        }

        if (isset($payload['required_selections'])) {
            $details[] = "Required Selections: {$payload['required_selections']}";
        }

        if (isset($payload['partial_credit']) && $payload['partial_credit']) {
            $details[] = 'Partial credit available';
        }

        if (isset($payload['case_sensitive']) && $payload['case_sensitive']) {
            $details[] = 'Case-sensitive answers';
        }

        return $details;
    }

    /**
     * Build question body with options/variants based on type
     */
    private function buildQuestionBody(): ?string
    {
        $payload = $this->payload ?? [];
        $html = '';

        // Handle different question types
        if (in_array($this->type?->value, ['single_select', 'multi_select'], true)) {
            $options = $payload['options'] ?? [];
            if (! empty($options)) {
                $html .= '<div class="space-y-2 mt-4">';
                foreach ($options as $idx => $option) {
                    $label = is_array($option) ? ($option['text'] ?? $option['label'] ?? '') : $option;
                    $isCorrect = false;

                    // Check if this is correct answer
                    if (isset($payload['correct_answer'])) {
                        $correctAnswer = $payload['correct_answer'];
                        if (is_array($correctAnswer)) {
                            $isCorrect = in_array($idx, $correctAnswer, true) || in_array((string) $idx, $correctAnswer, true);
                        } else {
                            $isCorrect = $idx === $correctAnswer || (string) $idx === $correctAnswer;
                        }
                    }

                    $bgClass = $isCorrect ? 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700' : 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600';
                    $checkIcon = $isCorrect ? '<span class="text-green-600 dark:text-green-400 font-bold mr-2">✓</span>' : '';

                    $html .= "<div class=\"p-3 border {$bgClass} rounded\">";
                    $html .= $checkIcon;
                    $html .= "<span class=\"text-gray-900 dark:text-gray-100\">{$label}</span>";
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        }

        // True/False, Yes/No/NG
        if (in_array($this->type?->value, ['true_false', 'yes_no_ng'], true)) {
            $correctAnswer = $payload['correct_answer'] ?? null;
            if ($correctAnswer) {
                $html .= '<div class="mt-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-300 dark:border-green-700 rounded">';
                $html .= '<span class="text-green-600 dark:text-green-400 font-bold">✓ Correct Answer: </span>';
                $html .= '<span class="text-gray-900 dark:text-gray-100">'.htmlspecialchars($correctAnswer).'</span>';
                $html .= '</div>';
            }
        }

        return $html ?? $this->toHTML($this->payload) ?: null;
    }

    public function toHTML($data, $title = ''): string
    {
        $html = $title ? "<h2>{$this->escape($title)}</h2>" : '';
        $html .= '<div class="json-container">';
        $html .= $this->renderValue($data);
        $html .= '</div>';

        return $html;
    }

    private function renderValue($value, $level = 0): string
    {
        $indent = str_repeat('  ', $level);

        if (is_array($value) || is_object($value)) {
            return $this->renderObject($value, $level);
        }

        return $this->renderScalar($value, $level);
    }

    private function renderObject($object, $level): string
    {
        $indent = str_repeat('  ', $level);
        $html = "{$indent}<div class=\"json-object\">\n";

        foreach ($object as $key => $value) {
            $html .= "{$indent}  <div class=\"json-property\">\n";
            $html .= "{$indent}    <strong class=\"json-key\">{$this->escape($key)}:</strong>\n";
            $html .= $this->renderValue($value, $level + 2);
            $html .= "{$indent}  </div>\n";
        }

        $html .= "{$indent}</div>\n";

        return $html;
    }

    private function renderScalar($value, $level): string
    {
        $indent = str_repeat('  ', $level);
        $displayValue = $this->escape($value);

        return "{$indent}  <span class=\"json-value\">{$displayValue}</span>\n";
    }

    private function escape($value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
