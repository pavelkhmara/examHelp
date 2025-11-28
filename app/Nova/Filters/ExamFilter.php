<?php

namespace App\Nova\Filters;

use App\Models\Exam;
use Illuminate\Http\Request;
use Laravel\Nova\Filters\Filter;

class ExamFilter extends Filter
{
    /**
     * The filter's component.
     */
    public $component = 'select-filter';

    /**
     * Apply the filter to the given query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
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
        // Оптимизация: выбираем только необходимые поля для уменьшения нагрузки на MySQL
        return Exam::select(['id', 'title'])
            ->orderBy('title')
            ->limit(1000) // Ограничиваем до 1000 экзаменов для фильтра
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
