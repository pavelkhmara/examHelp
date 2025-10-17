<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Заливает 3 экзамена:
 *  A: completed + валидный result
 *  B: failed + лог ошибки
 *  C: queued (пустой)
 */
class SampleExamSeeder extends Seeder
{
    public function run(): void
    {
        // МОДЕЛИ — проверь имена и при необходимости замени:
        $Exam            = \App\Models\Exam::class;
        $ExamCategory    = class_exists(\App\Models\ExamCategory::class) ? \App\Models\ExamCategory::class : null;
        $GenerationTask  = \App\Models\GenerationTask::class;
        $GenerationLog   = \App\Models\GenerationLog::class;

        // ===== Exam A: COMPLETED =====
        /** @var \App\Models\Exam $examA */
        $examA = $Exam::query()->updateOrCreate(
            ['slug' => 'ielts-demo'],
            [
                'title' => 'IELTS Demo',
                'level' => 'B2',
                'is_active' => true,
                'research_status' => 'completed',
                'meta' => ['note' => 'seed'],
            ]
        );

        // Категории (если модель существует)
        if ($ExamCategory) {
            $ExamCategory::query()->updateOrCreate(
                ['exam_id' => $examA->id, 'key' => 'reading'],
                ['name' => 'Reading']
            );
            $ExamCategory::query()->updateOrCreate(
                ['exam_id' => $examA->id, 'key' => 'listening'],
                ['name' => 'Listening']
            );
        }

        // Task + Logs + Result (валидный под наш валидатор)
        $resultA = [
            'exam_name' => 'ielts',
            'exam_description' => '',
            'timebox_minutes' => 3,
            'sources' => [
                ['url'=>'https://example.com/ielts','title'=>'IELTS Spec','publisher'=>'Example'],
            ],
            'archetypes' => [
                [
                    'id' => 'reading_tfn',
                    'name' => 'Reading — True/False/Not Given',
                    'section' => 'reading',
                    'question_types' => ['True/False/Not Given'],
                    'typical_distractors' => ['Paraphrase traps'],
                    'verbs' => ['select','decide'],
                    'weights' => ['Reading' => 1.0],
                    'numeric_ranges' => ['word_limits' => [1,3]],
                    'difficulty' => 'medium',
                ],
                [
                    'id' => 'listening_mcq',
                    'name' => 'Listening — MCQ (single)',
                    'section' => 'listening',
                    'question_types' => ['Multiple choice (1 correct)'],
                    'verbs' => ['choose'],
                    'category_weights' => ['Listening' => 1.0],
                    'numeric_ranges' => ['times' => [0, 60]],
                    'difficulty_band' => 'approx. 5–8',
                ],
            ],
            'exam_matrix_provided' => false,
            // total_score не обязателен; добавим для примера:
            'total_score' => ['min' => 0, 'max' => 100],
        ];

        $taskA = $GenerationTask::query()->create([
            'exam_id' => $examA->id,
            'type'    => 'research',
            'status'  => 'completed',
            'result'  => $resultA,
            'error'   => null,
        ]);

        $GenerationLog::query()->create([
            'generation_task_id' => $taskA->id,
            'stage'              => 'overview_validated',
            'response'            => ['result' => $resultA, 'message' => 'Overview JSON validated and saved (seed)'],
        ]);

        // ===== Exam B: FAILED =====
        $examB = $Exam::query()->updateOrCreate(
            ['slug' => 'polish-c1-demo'],
            [
                'title' => 'Polish C1 Demo',
                'level' => 'C1',
                'is_active' => true,
                'research_status' => 'failed',
                'meta' => ['note' => 'seed'],
            ]
        );

        if ($ExamCategory) {
            $ExamCategory::query()->updateOrCreate(
                ['exam_id' => $examB->id, 'key' => 'use-of-language'],
                ['name' => 'Use of language']
            );
        }

        $taskB = $GenerationTask::query()->create([
            'exam_id' => $examB->id,
            'type'    => 'research',
            'status'  => 'failed',
            'result'  => null,
            'error'   => 'Overview JSON validation failed',
        ]);

        $GenerationLog::query()->create([
            'generation_task_id' => $taskB->id,
            'stage'              => 'overview_validation_error',
            'response'            => ['errors' => ['sections' => ['missing']], 'data' => ['foo' => 'bar'], 'message' => 'Validation error (seed)'],
        ]);

        // ===== Exam C: QUEUED =====
        $examC = $Exam::query()->updateOrCreate(
            ['slug' => 'generic-queued'],
            [
                'title' => 'Generic Queued Exam',
                'level' => 'B1',
                'is_active' => true,
                'research_status' => 'queued',
                'meta' => ['note' => 'seed'],
            ]
        );

        if ($ExamCategory) {
            $ExamCategory::query()->updateOrCreate(
                ['exam_id' => $examC->id, 'key' => 'speaking'],
                ['name' => 'Speaking']
            );
        }
        // без tasks/logs — демонстрирует «нет структуры» (404 для эндпоинта)
    }
}
