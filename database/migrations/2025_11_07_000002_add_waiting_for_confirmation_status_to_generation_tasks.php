<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Добавляет статус 'waiting_for_confirmation' к GenerationTask.
     * Этот статус используется когда задача ожидает подтверждения от пользователя.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE `generation_tasks` MODIFY COLUMN `status` ENUM(
                    'queued',
                    'running',
                    'completed',
                    'failed',
                    'pending_confirmation',
                    'pending_clarification',
                    'cancelled',
                    'waiting_for_confirmation'
                ) DEFAULT 'queued'"
            );
        }
        // SQLite doesn't support ENUM, and the field was already created as string in original migration
        // No changes needed for SQLite - it already accepts any string value
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Remove 'waiting_for_confirmation' status
            DB::statement(
                "ALTER TABLE `generation_tasks` MODIFY COLUMN `status` ENUM(
                    'queued',
                    'running',
                    'completed',
                    'failed',
                    'pending_confirmation',
                    'pending_clarification',
                    'cancelled'
                ) DEFAULT 'queued'"
            );
        }
        // SQLite: no changes needed
    }
};
