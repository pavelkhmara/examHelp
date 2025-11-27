<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

/**
 * QuestionGroup Nova Resource
 *
 * Manages question groups (tasks) with shared stimulus and playback controls.
 * Used for listening/reading tasks where multiple questions share common audio/text/video.
 *
 * @extends resource<\App\Models\QuestionGroup>
 */
class QuestionGroup extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\QuestionGroup>
     */
    public static string $model = \App\Models\QuestionGroup::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'title';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
        'group_id',
        'title',
    ];

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return 'Question Groups';
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return 'Question Group';
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            // Basic Info Panel
            Panel::make('Basic Information', [
                BelongsTo::make('Exam', 'exam', Exam::class)
                    ->searchable()
                    ->withoutTrashed()
                    ->required()
                    ->help('Exam this question group belongs to'),

                BelongsTo::make('Section', 'section', ExamCategory::class)
                    ->searchable()
                    ->withoutTrashed()
                    ->required()
                    ->help('Section/Category this question group belongs to'),

                Text::make('Group ID', 'group_id')
                    ->rules('required', 'max:255')
                    ->help('Unique identifier for this question group (e.g., "listening-question-1")')
                    ->sortable(),

                Text::make('Title')
                    ->rules('nullable', 'max:500')
                    ->help('Display title (e.g., "Task I - Multiple Choice")')
                    ->sortable(),

                Number::make('Order')
                    ->min(0)
                    ->default(0)
                    ->help('Display order within section')
                    ->sortable(),

                Number::make('Questions Count')
                    ->exceptOnForms()
                    ->displayUsing(function () {
                        return $this->resource->questions()->count();
                    }),
            ]),

            // Instructions Panel
            Panel::make('Instructions', [
                Code::make('Instructions')
                    ->json()
                    ->rules('nullable', 'json')
                    ->help('Group-level instructions (JSON format: {brief: "...", full: "..."})')
                    ->hideFromIndex(),
            ]),

            // Stimulus Panel
            Panel::make('Stimulus', [
                Code::make('Stimulus')
                    ->json()
                    ->rules('nullable', 'json')
                    ->help('Shared stimulus for all questions (JSON format: {text_html: "...", audio: [...], images: [...], video: [...]})')
                    ->hideFromIndex(),
            ]),

            // Playback Settings Panel
            Panel::make('Playback Settings', [
                Code::make('Playback Settings', 'playback_settings')
                    ->json()
                    ->rules('nullable', 'json')
                    ->help('Audio/video playback controls (JSON format: {max_plays: 2, enforcement: "strict", show_counter: true, reset_on_navigation: true})')
                    ->hideFromIndex(),
            ]),

            // Metadata Panel
            Panel::make('Metadata', [
                Code::make('Metadata')
                    ->json()
                    ->rules('nullable', 'json')
                    ->help('Additional metadata (JSON format: {total_points: 6, suggested_time_sec: 300, duration_sec: 120})')
                    ->hideFromIndex(),
            ]),

            // Questions Relationship
            HasMany::make('Questions', 'questions', Question::class),
        ];
    }

    /**
     * Get the cards available for the request.
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
