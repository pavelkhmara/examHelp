<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Добавляет статус 'need_info' к Exam.research_status (желтый статус)
     * 2. Добавляет поле heartbeat_at к GenerationTask для отслеживания зависаний
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // Add need_info status to Exam.research_status
        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE `exams` MODIFY COLUMN `research_status` ENUM(
                    'queued',
                    'running_overview',
                    'completed',
                    'failed',
                    'need_info'
                ) DEFAULT 'queued'"
            );
        }
        // SQLite: no changes needed for ENUM

        // Add heartbeat_at to GenerationTask
        Schema::table('generation_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('generation_tasks', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('attempts');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // Remove need_info status from Exam.research_status
        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE `exams` MODIFY COLUMN `research_status` ENUM(
                    'queued',
                    'running_overview',
                    'completed',
                    'failed'
                ) DEFAULT 'queued'"
            );
        }

        // Remove heartbeat_at from GenerationTask
        Schema::table('generation_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('generation_tasks', 'heartbeat_at')) {
                $table->dropColumn('heartbeat_at');
            }
        });
    }
};
