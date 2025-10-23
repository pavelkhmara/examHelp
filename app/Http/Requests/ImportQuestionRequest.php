<?php

namespace App\Http\Requests;

use App\Domain\Questions\QuestionTypeContract;
use App\Rules\ValidQuestionPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(QuestionTypeContract::WHITELIST)],
            'payload' => ['required', 'array', new ValidQuestionPayload('type')],
            // другие поля импорта:
            'prompt' => ['sometimes', 'string'],
            'exam_id' => ['sometimes', 'integer', 'exists:exams,id'],
        ];
    }
}
