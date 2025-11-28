<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Models\ExamDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class UnzipAndAttachDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Audio file extensions that should be marked but not extracted for text
     */
    protected const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'wma'];

    public function __construct(
        public string $examId,
        public string $zipPath,
        public string $disk = 'local'
    ) {}

    public function handle(): void
    {
        $exam = Exam::findOrFail($this->examId);
        $fullZipPath = Storage::disk($this->disk)->path($this->zipPath);

        if (! file_exists($fullZipPath)) {
            Log::error('ZIP file not found for extraction', [
                'exam_id' => $this->examId,
                'path' => $this->zipPath,
            ]);

            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($fullZipPath) !== true) {
            Log::error('Failed to open ZIP archive', [
                'exam_id' => $this->examId,
                'path' => $this->zipPath,
            ]);

            return;
        }

        $extractedCount = 0;
        $audioCount = 0;
        $textExtractionDispatched = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip directories and hidden files
            if (str_ends_with($filename, '/') || str_starts_with(basename($filename), '.')) {
                continue;
            }

            // Skip macOS metadata files
            if (str_contains($filename, '__MACOSX') || basename($filename) === '.DS_Store') {
                continue;
            }

            $fileContent = $zip->getFromIndex($i);
            if ($fileContent === false) {
                Log::warning('Could not read file from ZIP', [
                    'exam_id' => $this->examId,
                    'filename' => $filename,
                ]);

                continue;
            }

            // Determine file extension and MIME type
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeType = $this->guessMimeType($extension);
            $isAudio = in_array($extension, self::AUDIO_EXTENSIONS, true);

            // Store the extracted file
            $storagePath = 'documents/extracted_'.time().'_'.basename($filename);
            Storage::disk($this->disk)->put($storagePath, $fileContent);

            // Create ExamDocument record
            $document = $exam->documents()->create([
                'original_name' => basename($filename),
                'disk' => $this->disk,
                'path' => $storagePath,
                'mime' => $mimeType,
                'size' => strlen($fileContent),
                'status' => 'uploaded',
                'meta' => [
                    'extracted_from_zip' => $this->zipPath,
                    'is_audio' => $isAudio,
                ],
            ]);

            $extractedCount++;

            if ($isAudio) {
                $audioCount++;
                Log::info('Audio file extracted from ZIP (not dispatching text extraction)', [
                    'exam_id' => $this->examId,
                    'document_id' => $document->id,
                    'filename' => $filename,
                ]);
            } else {
                // Dispatch text extraction job for non-audio files
                if (! config('documents.fake', false)) {
                    ExtractExamDocumentTextJob::dispatch($document->id);
                    $textExtractionDispatched++;
                }

                Log::info('Non-audio file extracted from ZIP, dispatched text extraction', [
                    'exam_id' => $this->examId,
                    'document_id' => $document->id,
                    'filename' => $filename,
                ]);
            }
        }

        $zip->close();

        Log::info('ZIP extraction completed', [
            'exam_id' => $this->examId,
            'zip_path' => $this->zipPath,
            'extracted_count' => $extractedCount,
            'audio_count' => $audioCount,
            'text_extraction_dispatched' => $textExtractionDispatched,
        ]);

        // Optionally, delete the original ZIP file after extraction
        // Storage::disk($this->disk)->delete($this->zipPath);
    }

    /**
     * Guess MIME type based on file extension
     */
    protected function guessMimeType(string $extension): string
    {
        $mimeMap = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'wma' => 'audio/x-ms-wma',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];

        return $mimeMap[$extension] ?? 'application/octet-stream';
    }
}
