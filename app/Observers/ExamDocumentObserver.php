<?php

namespace App\Observers;

use App\Jobs\AnalyzeExamMetadataJob;
use App\Models\ExamDocument;
use Illuminate\Support\Facades\Log;

class ExamDocumentObserver
{
    /**
     * Handle the ExamDocument "created" event.
     */
    public function created(ExamDocument $document): void
    {
        Log::info('ExamDocumentObserver: Document created, dispatching analysis', [
            'document_id' => $document->id,
            'exam_id' => $document->exam_id,
            'filename' => $document->filename,
        ]);

        // Automatically dispatch metadata analysis job when document is uploaded
        AnalyzeExamMetadataJob::dispatch($document->id);
    }

    /**
     * Handle the ExamDocument "updated" event.
     */
    public function updated(ExamDocument $document): void
    {
        //
    }

    /**
     * Handle the ExamDocument "deleted" event.
     */
    public function deleted(ExamDocument $document): void
    {
        //
    }

    /**
     * Handle the ExamDocument "restored" event.
     */
    public function restored(ExamDocument $document): void
    {
        //
    }

    /**
     * Handle the ExamDocument "force deleted" event.
     */
    public function forceDeleted(ExamDocument $document): void
    {
        //
    }
}
