<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add running_phase_a and running_phase_b statuses to research_status enum
     * for v2 two-phase generation architecture
     */
    public function up(): void
    {
        // MySQL: ALTER ENUM by recreating the column
        DB::statement("ALTER TABLE exams MODIFY COLUMN research_status ENUM(
            'queued',
            'running_overview',
            'running_phase_a',
            'running_phase_b',
            'running_categories',
            'running_examples',
            'running_rubrics',
            'completed',
            'failed'
        ) DEFAULT 'queued' NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum values
        DB::statement("ALTER TABLE exams MODIFY COLUMN research_status ENUM(
            'queued',
            'running_overview',
            'running_categories',
            'running_examples',
            'running_rubrics',
            'completed',
            'failed'
        ) DEFAULT 'queued' NOT NULL");
    }
};
