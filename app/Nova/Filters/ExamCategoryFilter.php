<?php

namespace App\Nova\Filters;

use Illuminate\Support\Facades\Log;
use Laravel\Nova\Filters\Filter;
use Illuminate\Http\Request;
use App\Models\Exam;

class ExamCategoryFilter extends Filter
{
    public $name = 'Filter by Exam';

    public function apply(Request $request, $query, $value)
    {
        Log::debug('ExamCategoryFilter apply', [
            'viaResource' => $request->viaResource(),
            // 'viaResourceId' => $request->viaResourceId(),
            'viaRelationship' => $request->viaRelationship(),
            'exam_id_param' => $request->get('exam_id'),
            'all_params' => $request->all(),
            'value' => $value,
        ]);

        return $query->where('exam_id', $value);
    }

    public function options(Request $request)
    {
        return Exam::orderBy('title')
            ->get()
            ->pluck('id', 'title')
            ->toArray();
    }

    public function default()
    {
        // Если передан exam_id в URL, используем его как значение по умолчанию
        if (request()->has('exam_id')) {
            return request()->get('exam_id');
        }
        
        return null;

        // $examId = request()->get('exam_id') 
        //         ?? request()->get('exam') 
        //         ?? (request()->viaResourceId() && request()->viaResource() === 'exams' ? request()->viaResourceId() : null);

        // return $examId;
    }
}