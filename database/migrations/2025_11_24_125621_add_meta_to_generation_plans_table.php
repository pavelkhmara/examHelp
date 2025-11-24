<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generation_plans', function (Blueprint $table) {
            // Add meta column for task-level parallelization tracking
            // Stores: total_filters, filters_completed, questions_generated, completed_filters, failed_filters
            $table->json('meta')->nullable()->after('plan_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_plans', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
