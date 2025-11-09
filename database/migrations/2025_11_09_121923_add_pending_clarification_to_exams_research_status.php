<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL doesn't support adding enum values directly
        // We need to alter the column with the full new enum list
        DB::statement("
            ALTER TABLE exams
            MODIFY COLUMN research_status ENUM(
                'queued',
                'running_overview',
                'completed',
                'failed',
                'pending_clarification'
            ) DEFAULT 'queued'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum values
        DB::statement("
            UPDATE exams
            SET research_status = 'failed'
            WHERE research_status = 'pending_clarification'
        ");

        DB::statement("
            ALTER TABLE exams
            MODIFY COLUMN research_status ENUM(
                'queued',
                'running_overview',
                'completed',
                'failed'
            ) DEFAULT 'queued'
        ");
    }
};
