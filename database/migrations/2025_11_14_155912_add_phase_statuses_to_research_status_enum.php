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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL: ALTER ENUM by recreating the column
            // IMPORTANT: Include ALL statuses from previous migrations
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
        } else {
            // SQLite: ENUMs are stored as TEXT with CHECK constraint
            // No need to modify for testing - constraint is not enforced strictly
            // The application layer handles validation
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Revert to state before this migration (with need_info and pending_clarification)
            DB::statement("ALTER TABLE exams MODIFY COLUMN research_status ENUM(
                'queued',
                'running_overview',
                'running_categories',
                'running_examples',
                'running_rubrics',
                'completed',
                'failed',
                'need_info',
                'pending_clarification'
            ) DEFAULT 'queued' NOT NULL");
        }
        // SQLite: no action needed
    }
};
