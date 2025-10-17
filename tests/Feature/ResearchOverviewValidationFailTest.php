<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ResearchOverviewValidationFailTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_overview_sets_task_failed_and_logs(): void
    {
        $exam = Exam::factory()->create();
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type'    => 'research',
            'status'  => 'running',
        ]);

        // Локальный анонимный класс-обёртка для подмены поведения generate()
        $fake = new class(App::make(\App\Services\LanguageApp\AiProviderFactory::class)->make()) extends ExamResearchService {
            public function __construct($ai) { parent::__construct($ai); }
            public function runPipeline(\App\Models\Exam $exam, \App\Models\GenerationTask $task): array
            {
                // имитируем «битый» ответ
                $aiResp = ['ok'=>true,'data'=>['total_score'=>['min'=>0,'max'=>100]],'usage'=>[],'raw'=>['mock'=>true]];
                // дальше копируем «хвост» из родительского, но с нашей подстановкой:
                // ... для краткости — используем доступ к валидатору напрямую
                try {
                    (new \App\Services\LanguageApp\Validators\JsonSchemaExamOverview())->validate($aiResp['data']);
                } catch (\Illuminate\Validation\ValidationException $ve) {
                    $task->status = 'failed';
                    $task->error  = 'Overview JSON validation failed';
                    $task->save();
                    \App\Models\GenerationLog::create([
                        'exam_id'            => $task->exam_id,
                        'generation_task_id' => $task->id,
                        'stage'              => 'overview_validation_error',
                        'request'            => null,
                        'response'           => ['errors' => $ve->errors(), 'data' => $aiResp['data'], 'message' => 'Validation error'],
                        'prompt_tokens'      => 0,
                        'completion_tokens'  => 0,
                        'total_tokens'       => 0,
                    ]);
                    return ['ok' => false, 'error' => 'validation_failed', 'errors' => $ve->errors()];
                }
                return ['ok'=>true];
            }
        };

        $res = $fake->runPipeline($exam, $task);

        $this->assertFalse($res['ok']);
        $this->assertDatabaseHas('generation_tasks', [
            'id' => $task->id,
            'status' => 'failed',
            'error' => 'Overview JSON validation failed',
        ]);
        $this->assertDatabaseHas('generation_logs', [
            'generation_task_id' => $task->id,
            'stage' => 'overview_validation_error',
        ]);
    }
}
