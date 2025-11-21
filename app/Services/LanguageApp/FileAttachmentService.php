<?php

namespace App\Services\LanguageApp;

use App\Models\ExamDocument;
use App\Services\LanguageApp\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileAttachmentService
{
    public function __construct(
        private readonly OpenAiProvider $provider
    ) {}

    /**
     * Check if file attachments are enabled.
     */
    public function isEnabled(): bool
    {
        return config('ai.file_attachments.enabled', false);
    }

    /**
     * Check if document MIME type is supported for attachments.
     */
    public function isSupportedMime(string $mime): bool
    {
        $supported = config('ai.file_attachments.supported_mimes', []);
        return in_array($mime, $supported, true);
    }

    /**
     * Upload document to OpenAI Files API.
     */
    public function uploadDocument(ExamDocument $doc): bool
    {
        if (!$this->isEnabled()) {
            Log::debug('FileAttachmentService: disabled, skipping upload', ['doc_id' => $doc->id]);
            return false;
        }

        if (!$this->isSupportedMime($doc->mime)) {
            Log::debug('FileAttachmentService: unsupported mime', ['doc_id' => $doc->id, 'mime' => $doc->mime]);
            return false;
        }

        // Get local file path
        $disk = $doc->disk ?? 'local';
        $filePath = Storage::disk($disk)->path($doc->path);

        if (!file_exists($filePath)) {
            Log::error('FileAttachmentService: file not found', ['doc_id' => $doc->id, 'path' => $filePath]);
            $doc->update(['file_attachment_status' => 'failed']);
            return false;
        }

        $doc->update(['file_attachment_status' => 'pending']);

        $result = $this->provider->uploadFile($filePath, $doc->original_name);

        if ($result['ok'] && isset($result['file_id'])) {
            $doc->update([
                'openai_file_id' => $result['file_id'],
                'file_attachment_status' => 'uploaded',
                'file_attached_at' => now(),
            ]);
            Log::info('FileAttachmentService: upload success', ['doc_id' => $doc->id, 'file_id' => $result['file_id']]);
            return true;
        }

        $doc->update(['file_attachment_status' => 'failed']);
        Log::error('FileAttachmentService: upload failed', ['doc_id' => $doc->id, 'error' => $result['error'] ?? 'unknown']);
        return false;
    }

    /**
     * Delete document from OpenAI Files API.
     */
    public function deleteDocument(ExamDocument $doc): bool
    {
        if (!$doc->openai_file_id) {
            return true; // Nothing to delete
        }

        $result = $this->provider->deleteFile($doc->openai_file_id);

        if ($result['ok']) {
            $doc->update([
                'openai_file_id' => null,
                'file_attachment_status' => null,
                'file_attached_at' => null,
            ]);
            return true;
        }

        Log::warning('FileAttachmentService: delete failed', ['doc_id' => $doc->id, 'error' => $result['error'] ?? 'unknown']);
        return false;
    }

    /**
     * Get file IDs for documents that have been uploaded.
     *
     * @param ExamDocument[]|iterable $documents
     * @return string[]
     */
    public function getFileIds(iterable $documents): array
    {
        $fileIds = [];
        foreach ($documents as $doc) {
            if ($doc->openai_file_id && $doc->file_attachment_status === 'uploaded') {
                $fileIds[] = $doc->openai_file_id;
            }
        }
        return $fileIds;
    }
}
