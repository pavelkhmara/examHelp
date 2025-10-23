<?php

namespace Tests\Feature\Api;

use App\Jobs\ExtractExamDocumentTextJob;
use App\Models\Exam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
// +++
use Illuminate\Support\Facades\Config;   // <— добавь
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_pdf_and_extract_text(): void
    {
        Config::set('documents.fake', true);

        $exam = Exam::factory()->create();
        $file = UploadedFile::fake()->create('sample.pdf', 20, 'application/pdf');

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $res = $this->postJson("/api/exams/{$exam->id}/documents", [
            'file' => $file,
        ])->assertCreated()->json('data');

        $docId = $res['document_id'];

        // 🔸 ГАРАНТИРОВАННО синхронно исполняем джобу для этого документа
        (new ExtractExamDocumentTextJob($docId))->handle();

        $show = $this->getJson("/api/documents/{$docId}")
            ->assertOk()
            ->json('data');

        $this->assertEquals('completed', $show['status']);
        $this->assertStringContainsString('FAKE_EXTRACT', $show['extracted_preview']);
    }
}
