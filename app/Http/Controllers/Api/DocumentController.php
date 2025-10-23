<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractExamDocumentTextJob;
use App\Models\Exam;
use App\Models\ExamDocument;
use App\Models\GenerationTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    /**
     * POST /api/exams/{exam}/documents
     * Multipart: file (pdf|jpg|jpeg|png)
     */
    public function store(Request $request, Exam $exam): JsonResponse
    {
        $maxMb = (int) config('documents.max_mb', 20);

        $data = $request->validate([
            'file' => [
                'required', 'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:'.($maxMb * 1024), // в КБ
            ],
        ]);

        $file = $data['file'];
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $disk = config('filesystems.default', 'local');
        $dir = "exams/{$exam->id}/documents/{$uuid}";
        $path = $file->storeAs($dir, 'original.'.$file->getClientOriginalExtension(), $disk);

        // Трекер процесса
        $task = GenerationTask::create([
            'exam_id' => $exam->id,
            'type' => 'doc_extract',
            'status' => 'queued',
            'request' => [
                'original_name' => $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ],
        ]);

        // Запись документа
        $doc = ExamDocument::create([
            'id' => $uuid,
            'exam_id' => $exam->id,
            'generation_task_id' => $task->id,
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => 'uploaded',
            'meta' => [],
        ]);

        // Джоба извлечения
        // В тестах или при sync-драйвере — выполняем job синхронно, чтобы сразу был статус "completed"
        if (app()->environment('testing') || config('queue.default') === 'sync') {
            (new ExtractExamDocumentTextJob($doc->id))->handle();
        } else {
            ExtractExamDocumentTextJob::dispatch($doc->id);
        }

        return response()->json([
            'data' => [
                'document_id' => $doc->id,
                'task_id' => $task->id,
                'status' => $doc->status,
            ],
        ], 201);
    }

    /**
     * GET /api/documents/{document}
     */
    public function show(ExamDocument $document): JsonResponse
    {
        $preview = '';
        if (! empty($document->extracted_text)) {
            $preview = mb_substr($document->extracted_text, 0, 500);
        }

        return response()->json([
            'data' => [
                'id' => $document->id,
                'exam_id' => $document->exam_id,
                'status' => $document->status,
                'error' => $document->error,
                'mime' => $document->mime,
                'size' => $document->size,
                'meta' => $document->meta,
                'extracted_preview' => $preview,
            ],
        ]);
    }
}
