<?php

namespace App\Rules;

use App\Domain\Questions\Validation\QuestionPayloadValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidQuestionPayload implements ValidationRule
{
    public function __construct(private readonly string $typeField = 'type') {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $payload = is_array($value) ? $value : [];
        $type = request()->input($this->typeField); // в FormRequest удобно
        try {
            QuestionPayloadValidator::validate((string)$type, $payload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $fld => $msgs) {
                foreach ($msgs as $m) {
                    $fail("{$attribute}: {$fld}: {$m}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ValidQuestionPayload: unexpected', ['e'=>$e]);
            $fail("{$attribute} validation failed: ".$e->getMessage());
        }
    }
}
