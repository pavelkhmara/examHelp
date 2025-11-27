<?php

namespace App\Nova;

use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
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
            BelongsTo::make('Question Group', 'questionGroup', QuestionGroup::class)
                ->nullable()
                ->searchable()
                ->help('Question group this question belongs to (for grouped tasks with shared stimulus)'),

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

            // Media Content (Audio + Images)
            new Panel('🎨 Media Content', $this->getMediaFields()),
        ];
    }

    /**
     * Поля для секции Media Content (Audio + Images)
     */
    protected function getMediaFields(): array
    {
        $fields = [];

        // ========== AUDIO SECTION ==========
        if ($this->resource && ($this->resource->audio_file_path || $this->resource->requires_audio)) {
            $fields[] = \Laravel\Nova\Fields\Heading::make('🎵 Audio');

            $fields[] = Boolean::make('Requires Audio', 'requires_audio')
                ->help('Indicates if this question requires audio generation')
                ->readonly();

            $fields[] = Text::make('Audio File Path', 'audio_file_path')
                ->onlyOnDetail()
                ->readonly()
                ->help('Path to the generated audio file');

            // Добавляем HTML плеер если есть аудио
            if ($this->resource->audio_file_path) {
                $audioUrl = $this->getAudioUrl();

                $fields[] = Text::make('Audio Player')
                    ->asHtml()
                    ->readonly()
                    ->resolveUsing(function () use ($audioUrl) {
                        if (! $audioUrl) {
                            return '<p style="color: #999;">Audio file not found</p>';
                        }

                        return '
                            <div style="padding: 10px; background: #f9fafb; border-radius: 6px;">
                                <audio controls style="width: 100%; max-width: 500px;">
                                    <source src="'.htmlspecialchars($audioUrl).'" type="audio/mpeg">
                                    Your browser does not support the audio element.
                                </audio>
                                <div style="margin-top: 10px;">
                                    <a href="'.htmlspecialchars($audioUrl).'"
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
        }

        // ========== IMAGE SECTION ==========
        if ($this->resource && ($this->resource->image_url || $this->resource->image_file_path)) {
            $fields[] = \Laravel\Nova\Fields\Heading::make('🖼️ Image');

            // Display winner image
            $imageUrl = $this->resource->image_url;
            if ($imageUrl) {
                $metadata = $this->resource->image_metadata ?? [];
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
                $alternatives = $this->resource->image_alternatives ?? [];
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
            if ($this->resource->image_metadata) {
                $fields[] = Code::make('Image Metadata', 'image_metadata')
                    ->json()
                    ->onlyOnDetail()
                    ->nullable()
                    ->help('AI selection metadata and image source information');
            }
        }

        // No media message
        if (empty($fields)) {
            $fields[] = Text::make('No Media', function () {
                return '<div class="text-gray-500 dark:text-gray-400 italic">No audio or image generated for this question</div>';
            })->asHtml()->onlyOnDetail();
        }

        return $fields;
    }

    /**
     * Получает URL аудио файла
     */
    protected function getAudioUrl(): ?string
    {
        if (! $this->resource || ! $this->resource->audio_file_path) {
            return null;
        }

        // Проверяем есть ли URL в instructions
        $instructions = $this->resource->instructions ?? [];
        if (! empty($instructions['audio_url'])) {
            return $instructions['audio_url'];
        }

        // Или генерируем из пути
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->resource->audio_file_path);
    }
}
