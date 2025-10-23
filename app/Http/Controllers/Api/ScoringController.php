<?php

namespace App\Http\Controllers\Api;

use App\Domain\Scoring\ScoringEngine;
use App\Http\Controllers\Controller;
use App\Services\LanguageApp\Validators\AnswerEnvelopeValidator;
use App\Services\LanguageApp\Validators\QuestionTypeContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Узкий превью-эндпоинт: валидируем task + envelope и считаем автоскор.
 * Не пишет в БД; полезен для FE/QA.
 */
final class ScoringController extends Controller
{
    public function preview(Request $req): JsonResponse
    {
        $task = (array) $req->input('task');         // валидированный task по нашему контракту
        $envelope = (array) $req->input('answer');       // AnswerEnvelope (FE → API)

        /** @var QuestionTypeContract $taskValidator */
        $taskValidator = app(QuestionTypeContract::class);
        /** @var AnswerEnvelopeValidator $ansValidator */
        $ansValidator = app(AnswerEnvelopeValidator::class);

        // 1) строгая проверка задания (типы/моды/минимальные поля)
        $task = $taskValidator->validateTask($task);

        // 2) строгая проверка формы ответа по типу
        $ansValidator->validate($envelope);
        if (($envelope['type'] ?? null) !== ($task['type'] ?? null)) {
            throw ValidationException::withMessages(['type' => 'answer.type must match task.type']);
        }

        // 3) прогон через движок (используем только scoring.*)
        $engine = ScoringEngine::default();
        $res = $engine->scoreTask($task, self::toUserMap($envelope));

        return response()->json([
            'ok' => true,
            'auto_gradable' => $res['auto_gradable'],
            'score' => $res['total'],
            'per_item' => $res['per_item'],
        ]);
    }

    /**
     * Приводим AnswerEnvelope к user-map, ожидаемому ScoringEngine.
     */
    private static function toUserMap(array $env): array
    {
        $type = (string) ($env['type'] ?? '');

        return match ($type) {
            'single_select','listen_mcq','dropdown_cloze','banked_cloze','short_answer','numeric','true_false','yes_no_ng','gap_cloze' => (array) ($env['answers'] ?? []),
            'multi_select' => (array) ($env['answers'] ?? []),
            'matching' => ['_matching' => (array) ($env['pairs'] ?? [])],
            'order_sentences','order_words' => ['_order' => (array) ($env['order'] ?? [])],
            'highlight_text' => ['_spans' => (array) ($env['spans'] ?? [])],
            'dictation','error_correction','writing_prompt' => ['_text' => (string) ($env['text'] ?? '')],
            'speaking_prompt' => ['_audioUrl' => (string) ($env['audioUrl'] ?? '')],
            default => [],
        };
    }
}
