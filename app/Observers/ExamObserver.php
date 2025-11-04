<?php

namespace App\Observers;

use App\Jobs\AnalyzeExamMetadataJob;
use App\Models\Exam;
use Illuminate\Support\Facades\Log;

class ExamObserver
{
    /**
     * Handle the Exam "created" event.
     */
    public function created(Exam $exam): void
    {
        // Auto-generate slug if empty
        if (empty($exam->slug)) {
            $slug = \Illuminate\Support\Str::slug($exam->title);
            $slug = substr($slug, 0, 20); // Max 20 characters

            // Ensure uniqueness
            $originalSlug = $slug;
            $counter = 1;
            while (Exam::where('slug', $slug)->where('id', '!=', $exam->id)->exists()) {
                $slug = substr($originalSlug, 0, 17) . '-' . $counter;
                $counter++;
            }

            $exam->slug = $slug;
            $exam->saveQuietly(); // Don't trigger observer again
        }

        // Dispatch metadata analysis job if there's something to analyze
        if (!empty($exam->user_input) || !empty($exam->description) || !empty($exam->identity)) {
            Log::info("ExamObserver: Dispatching metadata analysis for exam {$exam->id}");
            AnalyzeExamMetadataJob::dispatch($exam->id);
        } else {
            // Mark as completed if nothing to analyze
            $exam->update(['analysis_status' => 'completed']);
        }
    }

    /**
     * Handle the Exam "updated" event.
     */
    public function updated(Exam $exam): void
    {
        //
    }

    /**
     * Handle the Exam "deleted" event.
     */
    public function deleted(Exam $exam): void
    {
        //
    }

    /**
     * Handle the Exam "restored" event.
     */
    public function restored(Exam $exam): void
    {
        //
    }

    /**
     * Handle the Exam "force deleted" event.
     */
    public function forceDeleted(Exam $exam): void
    {
        //
    }
}
