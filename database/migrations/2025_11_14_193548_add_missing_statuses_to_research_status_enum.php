<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add missing 'need_info' and 'pending_clarification' statuses back to research_status enum.
     * These were removed by mistake in 2025_11_14_155912_add_phase_statuses migration.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            // Skip enum alteration for non-MySQL (e.g., sqlite in tests)
            return;
        }

        // Include ALL statuses: existing phase statuses + missing need_info and pending_clarification
        DB::statement("ALTER TABLE exams MODIFY COLUMN research_status ENUM(
            'queued',
            'running_overview',
            'running_phase_a',
            'running_phase_b',
            'running_categories',
            'running_examples',
            'running_rubrics',
            'completed',
            'failed',
            'need_info',
            'pending_clarification'
        ) DEFAULT 'queued' NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        // Revert to state without need_info and pending_clarification
        // WARNING: This will fail if any exams have these statuses
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
};
