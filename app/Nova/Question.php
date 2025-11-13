<?php

namespace App\Nova;

use App\Nova\ExamCategory;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

class Question extends Resource
{
    public static $model = \App\Models\Question::class;
    public static $title = 'question_id';
    public static $search = ['question_id', 'type', 'metadata->topic'];
    public static $group = 'Language App';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Exam')->searchable(),
            BelongsTo::make('Section', 'section', ExamCategory::class)->searchable(),

            Code::make('Question ID', 'question_id')
                ->onlyOnDetail(),

            Select::make('Type')->options([
                'single_select' => 'Single Select',
                'multi_select' => 'Multi Select',
                'true_false' => 'True/False',
                'yes_no_ng' => 'Yes/No/NG',
                'dropdown_cloze' => 'Dropdown Cloze',
                'gap_cloze' => 'Gap Cloze',
                'banked_cloze' => 'Banked Cloze',
                'matching' => 'Matching',
                'order_sentences' => 'Order Sentences',
                'order_words' => 'Order Words',
                'highlight_text' => 'Highlight Text',
                'short_answer' => 'Short Answer',
                'numeric' => 'Numeric',
                'dictation' => 'Dictation',
                'writing_prompt' => 'Writing Prompt',
                'speaking_prompt' => 'Speaking Prompt',
                'listen_mcq' => 'Listening MCQ',
                'video_mcq' => 'Video MCQ',
                'translation' => 'Translation',
                'roleplay' => 'Roleplay',
                'note_completion' => 'Note Completion',
            ])->displayUsingLabels()->sortable(),

            Badge::make('Status')->map([
                'draft' => 'warning',
                'published' => 'success',
                'archived' => 'danger',
            ])->sortable(),

            Number::make('Time Limit (sec)', 'time_limit_sec')->sortable(),

            Code::make('Instructions', fn () => json_encode($this->instructions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->language('json')
                ->onlyOnDetail(),

            Code::make('Stimulus', fn () => json_encode($this->stimulus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->language('json')
                ->onlyOnDetail(),

            Code::make('Interaction', fn () => json_encode($this->interaction, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->language('json')
                ->onlyOnDetail(),

            Code::make('Response', fn () => json_encode($this->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->language('json')
                ->onlyOnDetail(),

            Code::make('Scoring', fn () => json_encode($this->scoring, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                ->language('json')
                ->onlyOnDetail(),
        ];
    }
}


