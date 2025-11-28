<?php

namespace App\Jobs;

use App\Models\ExamDocument;
use App\Models\GenerationLog;
use App\Models\GenerationTask;
use App\Services\LanguageApp\FileAttachmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\PdfToText\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;

class ExtractExamDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $documentId) {}

    public function handle(): void
    {
        /** @var ExamDocument $doc */
        $doc = ExamDocument::query()->findOrFail($this->documentId);

        /** @var GenerationTask|null $task */
        $task = $doc->generation_task_id ? GenerationTask::find($doc->generation_task_id) : null;

        $this->markTask($task, 'running', null);

        $doc->update(['status' => 'extracting', 'error' => null]);

        $disk = $doc->disk ?: config('filesystems.default', 'local');
        $fullPath = Storage::disk($disk)->path($doc->path);

        $text = '';
        $ok = true;
        $error = null;

        $fake = (bool) config('documents.fake', false);
        try {
            if ($fake) {
                $text = "FAKE_EXTRACT for {$doc->original_name}";
            } else {
                $text = $this->extract($doc->mime, $fullPath);
            }

            $text = trim($text);

            if ($text === '') {
                $ok = false;
                $error = 'Empty extract';
            }
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        // Лог
        if ($task) {
            GenerationLog::create([
                'generation_task_id' => $task->id,
                'stage' => 'document.extract',
                'request' => [
                    'mime' => $doc->mime,
                    'path' => $doc->path,
                ],
                'response' => [
                    'ok' => $ok,
                    'chars' => mb_strlen($text),
                    'error' => $error,
                ],
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ]);
        }

        // Обновления
        $doc->update([
            'status' => $ok ? 'completed' : 'failed',
            'extracted_text' => $ok ? $text : null,
            'error' => $ok ? null : $error,
            'meta' => array_merge($doc->meta ?? [], [
                'chars' => mb_strlen($text),
            ]),
        ]);

        $this->markTask($task, $ok ? 'completed' : 'failed', [
            'ok' => $ok,
            'document_id' => $doc->id,
            'chars' => mb_strlen($text),
            'error' => $error,
        ]);

        // Auto-upload to OpenAI Files API if enabled
        if ($ok && config('ai.file_attachments.enabled') && config('ai.file_attachments.auto_upload')) {
            try {
                $fileService = app(FileAttachmentService::class);
                $fileService->uploadDocument($doc);
            } catch (\Throwable $e) {
                // Non-blocking - log and continue
                \Illuminate\Support\Facades\Log::warning('FileAttachment auto-upload failed', [
                    'doc_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function extract(string $mime, string $fullPath): string
    {
        // Plain text files - just read the content
        if (str_contains($mime, 'text/plain') || str_contains($mime, 'text/')) {
            return file_get_contents($fullPath);
        }

        if (str_contains($mime, 'pdf')) {
            $bin = config('documents.pdftotext_bin');

            $pdf = new Pdf($bin);
            $pdf->setPdf($fullPath);

            return $pdf->text();
        }

        if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') || str_contains($mime, 'png')) {
            if (! config('documents.ocr_enabled', false)) {
                throw new RuntimeException('OCR disabled (enable DOC_OCR_ENABLED to use Tesseract).');
            }
            $langs = (string) config('documents.ocr_langs', 'eng');
            $tess = new TesseractOCR($fullPath);
            // @phpstan-ignore-next-line - TesseractOCR library method exists but not in PHPStan stubs
            $tess->lang(...array_map('trim', explode('+', $langs)));
            $binPath = config('documents.tesseract_bin');
            if ($binPath) {
                $tess->executable($binPath);
            }

            return $tess->run();
        }

        throw new RuntimeException('Unsupported MIME: '.$mime);
    }

    protected function markTask(?GenerationTask $task, string $status, ?array $result): void
    {
        if (! $task) {
            return;
        }

        $task->update([
            'status' => $status,
            'result' => $result ? array_merge(($task->result ?? []), $result) : $task->result,
        ]);
    }
}
