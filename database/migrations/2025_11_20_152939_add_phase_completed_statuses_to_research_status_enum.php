<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add phase_a_completed and phase_b_completed statuses to research_status enum.
     * These statuses are used when Phase A or Phase B completes successfully,
     * indicating that the next phase can be run.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            // Skip enum alteration for non-MySQL (e.g., sqlite in tests)
            return;
        }

        // Add phase_a_completed and phase_b_completed to the enum
        DB::statement("ALTER TABLE exams MODIFY COLUMN research_status ENUM(
            'queued',
            'running_overview',
            'running_phase_a',
            'phase_a_completed',
            'running_phase_b',
            'phase_b_completed',
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

        // Remove phase_a_completed and phase_b_completed from the enum
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
            'failed',
            'need_info',
            'pending_clarification'
        ) DEFAULT 'queued' NOT NULL");
    }
};
