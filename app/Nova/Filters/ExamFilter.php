<?php

namespace App\Nova\Filters;

use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;
use App\Models\Exam;

class ExamFilter extends Filter
{
    /**
     * The filter's component.
     */
    public $component = 'select-filter';

    /**
     * Apply the filter to the given query.
     */
    public function apply(Request $request, $query, $value)
    {
        return $query->where('exam_id', $value);
    }

    /**
     * Get the filter's available options.
     */
    public function options(Request $request)
    {
        return Exam::orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }

    /**
     * Get the displayable name of the filter.
     */
    public function name()
    {
        return 'Filter by Exam';
    }
}