<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds explicit 'status' column to replace heuristic check (interaction empty = skeleton).
     *
     * Phase 2.2: Explicit Status Field
     * @see docs/architecture/phase2-transition-plan.md
     */
    public function up(): void
    {
        // Check if column already exists (prevents duplicate column error in tests)
        if (! Schema::hasColumn('questions', 'status')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->enum('status', ['skeleton', 'draft', 'published', 'archived'])
                    ->default('draft')
                    ->after('frozen_at')
                    ->comment('Question lifecycle status: skeleton (placeholder), draft (synthesized), published (live), archived (removed)');
            });

            // Backfill existing questions:
            // - If interaction is empty or minimal (< 3 chars JSON = "[]" or "{}") → skeleton
            // - Otherwise → draft
            DB::statement("
                UPDATE questions
                SET status = CASE
                    WHEN interaction IS NULL OR JSON_LENGTH(interaction) <= 2 THEN 'skeleton'
                    ELSE 'draft'
                END
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
