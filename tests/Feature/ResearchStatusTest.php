<?php

namespace Tests\Feature;

use App\Jobs\RunExamResearchJob;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResearchStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_status_flow_to_completed_with_mock(): void
    {
        // Включаем мок у провайдера
        Config::set('ai.provider', 'mock');
        Config::set('ai.mock.model', 'mock_model');

        $exam = Exam::factory()->create([
            'research_status' => 'queued',
            'is_active' => true,
        ]);

        // Запускаем джобу синхронно
        (new RunExamResearchJob($exam->id, 'notes'))->handle(app('App\Services\LanguageApp\ExamResearchService'));

        $exam->refresh();
        $this->assertEquals('completed', $exam->research_status);
    }
}
