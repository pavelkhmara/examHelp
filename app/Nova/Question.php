<?php

namespace App\Nova;

use App\Nova\ExamCategory;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
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

            // Audio fields
            new Panel('Audio', $this->audioFields()),
        ];
    }

    /**
     * Поля для секции Audio
     */
    protected function audioFields(): array
    {
        $fields = [
            Boolean::make('Requires Audio', 'requires_audio')
                ->help('Indicates if this question requires audio generation')
                ->readonly(),

            Text::make('Audio File Path', 'audio_file_path')
                ->onlyOnDetail()
                ->readonly()
                ->help('Path to the generated audio file'),
        ];

        // Добавляем HTML плеер если есть аудио
        if ($this->resource && $this->resource->audio_file_path) {
            $audioUrl = $this->getAudioUrl();

            $fields[] = Text::make('Audio Player')
                ->asHtml()
                ->readonly()
                ->resolveUsing(function () use ($audioUrl) {
                    if (!$audioUrl) {
                        return '<p style="color: #999;">Audio file not found</p>';
                    }

                    return '
                        <div style="padding: 10px; background: #f9fafb; border-radius: 6px;">
                            <audio controls style="width: 100%; max-width: 500px;">
                                <source src="' . htmlspecialchars($audioUrl) . '" type="audio/mpeg">
                                Your browser does not support the audio element.
                            </audio>
                            <div style="margin-top: 10px;">
                                <a href="' . htmlspecialchars($audioUrl) . '"
                                   download
                                   style="color: #4f46e5; text-decoration: none; font-weight: 500;">
                                    📥 Download Audio File
                                </a>
                            </div>
                        </div>
                    ';
                })
                ->onlyOnDetail();
        }

        return $fields;
    }

    /**
     * Получает URL аудио файла
     */
    protected function getAudioUrl(): ?string
    {
        if (!$this->resource || !$this->resource->audio_file_path) {
            return null;
        }

        // Проверяем есть ли URL в instructions
        $instructions = $this->resource->instructions ?? [];
        if (!empty($instructions['audio_url'])) {
            return $instructions['audio_url'];
        }

        // Или генерируем из пути
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->resource->audio_file_path);
    }
}


