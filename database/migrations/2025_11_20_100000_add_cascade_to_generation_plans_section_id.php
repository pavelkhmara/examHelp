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
     * Converts generation_plans.section_id from string to unsignedInteger
     * and adds CASCADE foreign key to exam_categories
     */
    public function up(): void
    {
        // IMPORTANT: Delete all existing generation plans before schema change
        // This is safe because plans will be regenerated from structure_v2
        DB::table('generation_plans')->truncate();

        // Check and drop existing indexes if they exist
        $indexes = DB::select("SHOW INDEX FROM generation_plans WHERE Key_name IN ('generation_plans_exam_id_section_id_unique', 'generation_plans_section_id_index')");

        if (count($indexes) > 0) {
            Schema::table('generation_plans', function (Blueprint $table) use ($indexes) {
                $indexNames = array_column($indexes, 'Key_name');

                if (in_array('generation_plans_exam_id_section_id_unique', $indexNames)) {
                    $table->dropUnique(['exam_id', 'section_id']);
                }
                if (in_array('generation_plans_section_id_index', $indexNames)) {
                    $table->dropIndex(['section_id']);
                }
            });
        }

        // Convert section_id from string to unsignedInteger
        DB::statement('ALTER TABLE generation_plans MODIFY COLUMN section_id INT UNSIGNED NOT NULL');

        Schema::table('generation_plans', function (Blueprint $table) {
            // Add foreign key with CASCADE
            $table->foreign('section_id')
                  ->references('id')
                  ->on('exam_categories')
                  ->onDelete('cascade');

            // Re-add indexes
            $table->index('section_id');
            $table->unique(['exam_id', 'section_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_plans', function (Blueprint $table) {
            // Drop new foreign key and indexes
            $table->dropForeign(['section_id']);
            $table->dropUnique(['exam_id', 'section_id']);
            $table->dropIndex(['section_id']);
        });

        // Revert section_id back to string
        DB::statement('ALTER TABLE generation_plans MODIFY COLUMN section_id VARCHAR(255) NOT NULL');

        Schema::table('generation_plans', function (Blueprint $table) {
            // Re-add old indexes
            $table->index('section_id');
            $table->unique(['exam_id', 'section_id']);
        });
    }
};
