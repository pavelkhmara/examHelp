<?php

namespace App\Nova;

use App\Nova\Fields\CollapsiblePanel;
use App\Services\Nova\ExamFullViewRenderer;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * ExamFullView Nova Resource
 *
 * Displays full exam view with all sections and questions.
 * This is a read-only resource for viewing complete exam structure.
 *
 * @extends resource<\App\Models\Exam>
 */
class ExamFullView extends Resource
{
    public static string $model = \App\Models\Exam::class;

    public static $title = 'title';

    public static $search = ['id', 'title'];

    public static $group = 'Language App';

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return 'Exam Full View';
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return 'Exam Full View';
    }

    /**
     * Determine if this resource is available for navigation.
     */
    public static function availableForNavigation(\Illuminate\Http\Request $request): bool
    {
        // Hide from main navigation - access via Exam resource link
        return false;
    }

    /**
     * Get the URI key for the resource.
     */
    public static function uriKey(): string
    {
        return 'exam-full-view';
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(NovaRequest $request): array
    {
        $renderer = new ExamFullViewRenderer;
        $html = $renderer->renderExam($this->resource);

        return [
            // Title field (for display)
            Text::make('Exam Title', 'title')
                ->readonly()
                ->onlyOnDetail(),

            // Main full view (non-collapsible, sections are collapsible inside)
            CollapsiblePanel::make('📋 Full Exam View', 'full_exam_view')
                ->heading('📋 Full Exam View')
                ->content($html)
                ->expanded()
                ->onlyOnDetail(),
        ];
    }

    /**
     * Disable create, update, delete actions
     */
    public static function authorizedToCreate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToForceDelete(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToRestore(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToReplicate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }
}
