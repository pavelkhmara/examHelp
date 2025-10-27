<?php

namespace App\Nova;

use App\Domain\Taxonomy\QuestionType;
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
            new Panel('📋 Question Overview', $this->getOverviewFields()),
            new Panel('📝 Question Content', $this->getContentFields()),
            new Panel('✅ Example Response & Assessment', $this->getAssessmentFields()),
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
}
