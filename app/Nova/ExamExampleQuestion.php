<?php

namespace App\Nova;

use App\Nova\Filters\ExamFilter;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Domain\Taxonomy\QuestionType;

class ExamExampleQuestion extends Resource
{
    public static $model = \App\Models\ExamExampleQuestion::class;

    public static $title = 'question';

    public static $search = ['id', 'question'];

    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Exam', 'exam', Exam::class)
                ->searchable()
                ->sortable()
                ->readonly(function ($request) {
                    return ! $request->isResourceIndexRequest() && ! $request->isResourceDetailRequest();
                }),
            BelongsTo::make('Category', 'category', ExamCategory::class)
                ->searchable()
                ->nullable(),
                Badge::make('Type','type')
                        ->map([
                            QuestionType::SINGLE_SELECT->value   => 'info',
                            QuestionType::MULTI_SELECT->value    => 'info',
                            QuestionType::TRUE_FALSE->value      => 'success',
                            QuestionType::YES_NO_NG->value       => 'success',
                            QuestionType::DROPDOWN_CLOZE->value  => 'warning',
                            QuestionType::GAP_CLOZE->value       => 'warning',
                            QuestionType::BANKED_CLOZE->value    => 'warning',
                            QuestionType::MATCHING->value        => 'info',
                            QuestionType::ORDER_SENTENCES->value => 'info',
                            QuestionType::ORDER_WORDS->value     => 'info',
                            QuestionType::HIGHLIGHT_TEXT->value  => 'info',
                            QuestionType::SHORT_ANSWER->value    => 'warning',
                            QuestionType::NUMERIC->value         => 'success',
                            QuestionType::LISTEN_MCQ->value      => 'info',
                            QuestionType::DICTATION->value       => 'warning',
                            QuestionType::ERROR_CORRECTION->value=> 'danger',
                            QuestionType::WRITING_PROMPT->value  => 'danger',
                            QuestionType::SPEAKING_PROMPT->value => 'danger',
                        ])
                        ->labels([
                            QuestionType::SINGLE_SELECT->value   => 'Single',
                            QuestionType::MULTI_SELECT->value    => 'Multi',
                            QuestionType::TRUE_FALSE->value      => 'T/F',
                            QuestionType::YES_NO_NG->value       => 'Yes/No/NG',
                            QuestionType::DROPDOWN_CLOZE->value  => 'Dropdown Cloze',
                            QuestionType::GAP_CLOZE->value       => 'Gap Cloze',
                            QuestionType::BANKED_CLOZE->value    => 'Banked Cloze',
                            QuestionType::MATCHING->value        => 'Matching',
                            QuestionType::ORDER_SENTENCES->value => 'Order Sentences',
                            QuestionType::ORDER_WORDS->value     => 'Order Words',
                            QuestionType::HIGHLIGHT_TEXT->value  => 'Highlight',
                            QuestionType::SHORT_ANSWER->value    => 'Short Answer',
                            QuestionType::NUMERIC->value         => 'Numeric',
                            QuestionType::LISTEN_MCQ->value      => 'Listen MCQ',
                            QuestionType::DICTATION->value       => 'Dictation',
                            QuestionType::ERROR_CORRECTION->value=> 'Error Correction',
                            QuestionType::WRITING_PROMPT->value  => 'Writing',
                            QuestionType::SPEAKING_PROMPT->value => 'Speaking',
                        ]),
            Text::make('Question')->rules('required')->onlyOnForms(),
            Text::make('Question Preview', 'question')->exceptOnForms()->onlyOnDetail(),
            // Code::make('Good Answer')->json()
            //     ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            //     ->fillUsing(fn ($r, $m, $a, $ra) => $m->$a = json_decode($r->$ra ?: 'null', true))
            //     ->hideFromIndex(),
            // Code::make('Average Answer')->json()
            //     ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            //     ->fillUsing(fn ($r, $m, $a, $ra) => $m->$a = json_decode($r->$ra ?: 'null', true))
            //     ->hideFromIndex(),
            // Code::make('Bad Answer')->json()
            //     ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            //     ->fillUsing(fn ($r, $m, $a, $ra) => $m->$a = json_decode($r->$ra ?: 'null', true))
            //     ->hideFromIndex(),
            // Code::make('Rubric Breakdown')->json()
            //     ->resolveUsing(fn ($v) => is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            //     ->fillUsing(fn ($r, $m, $a, $ra) => $m->$a = json_decode($r->$ra ?: 'null', true))
            //     ->hideFromIndex(),
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
