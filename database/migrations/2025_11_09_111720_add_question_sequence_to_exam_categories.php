<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\ExamCategory;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add question_sequence to exam_categories.meta (without removing old keys)
     * This implements Variant A (Global templates + references) from RECOMMENDATIONS.md
     */
    public function up(): void
    {
        // No schema changes - everything is in JSON meta
        // Just data migration to add question_sequence

        DB::transaction(function() {
            $categories = ExamCategory::all();

            foreach ($categories as $category) {
                $meta = $category->meta ?? [];
                $steps = $meta['steps'] ?? [];

                // Skip if already has question_sequence
                if (isset($meta['question_sequence'])) {
                    continue;
                }

                // Build question_sequence from steps
                $sequence = [];
                foreach ($steps as $step) {
                    $sequence[] = [
                        'template_id' => $step['archetype_id'] ?? null,
                        'order' => $step['order'] ?? null,
                    ];
                }

                // Add question_sequence (keep old keys for backward compatibility)
                $meta['question_sequence'] = $sequence;
                $category->meta = $meta;
                $category->saveQuietly(); // Avoid triggering observers
            }
        });

        \Log::info('Migration: Added question_sequence to ' . ExamCategory::count() . ' categories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove question_sequence from meta
        DB::transaction(function() {
            $categories = ExamCategory::all();

            foreach ($categories as $category) {
                $meta = $category->meta ?? [];

                if (isset($meta['question_sequence'])) {
                    unset($meta['question_sequence']);
                    $category->meta = $meta;
                    $category->saveQuietly();
                }
            }
        });

        \Log::info('Migration rollback: Removed question_sequence from categories');
    }
};
