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
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            // Skip enum alteration for non-MySQL (e.g., sqlite in tests)
            return;
        }

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
        $driver = DB::getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

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
