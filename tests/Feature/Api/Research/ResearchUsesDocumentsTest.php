<?php

namespace Tests\Feature\Api\Research;

use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationTask;
use App\Services\LanguageApp\ExamResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group broken
 */
class ResearchUsesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_is_preferred_and_sources_have_provenance(): void
    {
        // 1) Экзамен
        $exam = Exam::factory()->create([
            'title' => 'B1 Polish',
            'level' => 'B1',
            'description' => 'Polish language exam',
            'is_active' => true,
        ]);

        // 2) Документ: ОБЯЗАТЕЛЬНО задать path и корректные имена полей
        $doc = ExamDocument::create([
            'exam_id' => $exam->id,
            'status' => 'completed',
            'original_name' => 'b1_sample.pdf',
            'disk' => config('filesystems.default', 'local'),
            'path' => "exams/{$exam->id}/documents/sample.pdf",
            'mime' => 'application/pdf',
            'size' => 1234,
            'extracted_text' => 'DOC_HINT_FAKE_CONTENT lorem ipsum...',
        ]);

        // 3) Таск ресёрча
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'research',
            'status' => 'running',
        ]);

        // 4) Запуск пайплайна
        $svc = app(ExamResearchService::class);
        $res = $svc->runPipeline($exam, $task);

        $this->assertTrue($res['ok']);
        $task->refresh();
        $this->assertIsArray($task->result);

        // 5) Проверяем sources
        $sources = $task->result['sources'] ?? [];
        $this->assertNotEmpty($sources, 'sources must not be empty');

        // Документ должен идти первым
        $first = $sources[0];
        $this->assertEquals('document', $first['provenance'] ?? null);
        $this->assertEquals($doc->id, $first['doc_id'] ?? null);

        // И должен присутствовать хотя бы один web-источник
        $hasWeb = collect($sources)->contains(fn ($s) => ($s['provenance'] ?? '') === 'web');
        $this->assertTrue($hasWeb, 'web source is expected alongside document source');
    }
}
