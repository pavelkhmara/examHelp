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
        Schema::table('exam_categories', function (Blueprint $table) {
            $table->json('task_archetypes')->nullable()->after('skill')
                ->comment('Task archetypes from Phase B (v2 architecture)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_categories', function (Blueprint $table) {
            $table->dropColumn('task_archetypes');
        });
    }
};
