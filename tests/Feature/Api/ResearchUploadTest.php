<?php

namespace Tests\Feature\Api;

use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * @group broken
 */
class ResearchUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_accepts_file_upload_and_creates_document(): void
    {
        $exam = Exam::factory()->create();

        Storage::fake('local');
        Queue::fake();

        $file = UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf');

        $res = $this->post('/api/exams/'.$exam->id.'/research', [
            'user_input' => json_encode([
                'language' => 'English',
                'target' => ['level' => 'B2'],
            ]),
            'document' => $file,
        ]);

        $res->assertStatus(202);
        $this->assertDatabaseHas('exam_documents', [
            'exam_id' => $exam->id,
            'original_name' => 'spec.pdf',
            'disk' => 'local',
            'status' => 'uploaded',
        ]);

        // Проверим, что файл действительно положили на диск
        $doc = \App\Models\ExamDocument::query()->where('exam_id', $exam->id)->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($doc->path));
    }
}
