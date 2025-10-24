<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE `generation_tasks` MODIFY COLUMN `status` ENUM('queued', 'running', 'completed', 'failed', 'pending_confirmation', 'pending_clarification') DEFAULT 'queued'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE `generation_tasks` MODIFY COLUMN `status` ENUM('queued', 'running', 'completed', 'failed') DEFAULT 'queued'"
        );
    }
};
