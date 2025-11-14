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
     * Migrates database structure to support v2 architecture:
     * - Add meta field to exams for structure_v2 storage
     * - Add v2 section fields to exam_categories (skill, duration_min, etc.)
     * - Recreate questions table with v2 question archetype schema
     */
    public function up(): void
    {
        // 1. Update exams table - meta field already exists, skip

        // 2. Update exam_categories table - add v2 section fields
        Schema::table('exam_categories', function (Blueprint $table) {
            // Check if columns don't exist before adding
            if (!Schema::hasColumn('exam_categories', 'skill')) {
                $table->string('skill')->nullable()->after('name');
            }
            if (!Schema::hasColumn('exam_categories', 'duration_min')) {
                $table->integer('duration_min')->nullable()->after('skill');
            }
            if (!Schema::hasColumn('exam_categories', 'max_score')) {
                $table->decimal('max_score', 8, 2)->nullable()->after('duration_min');
            }
            if (!Schema::hasColumn('exam_categories', 'min_pass_percent')) {
                $table->decimal('min_pass_percent', 5, 2)->nullable()->after('max_score');
            }
            if (!Schema::hasColumn('exam_categories', 'time_policy')) {
                $table->string('time_policy')->nullable()->after('min_pass_percent');
            }
            // order already exists
        });

        // 3. Drop existing questions table (old structure incompatible with v2)
        // Disable foreign key checks on MySQL only
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }
        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        // 4. Recreate questions table with v2 schema
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->char('exam_id', 36);
            $table->unsignedInteger('section_id'); // FK to exam_categories

            // Core v2 fields (required)
            $table->string('question_id')->unique(); // v2 id field
            $table->string('type'); // enum from v2: single_select, multi_select, etc
            $table->json('skills_measured'); // array of skills
            $table->integer('time_limit_sec');

            // v2 structure fields (JSON, required)
            $table->json('instructions'); // {brief, full, audio_url?, video_url?, l10n?}
            $table->json('stimulus'); // {text_html?, images[], audio[], video[], resources?, media_metadata?}
            $table->json('interaction'); // {response_type, options[]?, pairs[]?, bank[]?, spans[]?}
            $table->json('response'); // {mode, max_words?, recording_limit_sec?, attempts?, validation?}
            $table->json('scoring'); // {method, answer_key?, partial_rules[]?, rubric?, max_score?}
            $table->json('metadata'); // {cefr[], difficulty, topic, language, sources[]?, tags[]?}

            // Optional v2 fields (JSON, nullable)
            $table->json('constraints')->nullable(); // {dictionary_allowed?, calculator_allowed?, notes_allowed?, backtracking_allowed?, proctoring?}
            $table->json('randomization')->nullable(); // {shuffle_options?, shuffle_bank?, shuffle_pairs?}
            $table->json('outcome_reporting')->nullable(); // {weight?, feedback_visibility?, feedback_rules[]?}
            $table->json('io_signature')->nullable(); // {stimulus[], response[]} - for analytics/validation
            $table->json('typical_errors')->nullable(); // array of common mistakes
            $table->json('ui_hints')->nullable(); // UI rendering hints
            $table->json('accessibility')->nullable(); // Accessibility settings

            // Status fields
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('frozen_at')->nullable(); // When question was frozen (immutable)

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            $table->foreign('section_id')->references('id')->on('exam_categories')->onDelete('cascade');

            // Indexes
            $table->index(['exam_id', 'section_id', 'status']);
            $table->index('type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse v2 changes - meta field was pre-existing, don't drop

        Schema::table('exam_categories', function (Blueprint $table) {
            if (Schema::hasColumn('exam_categories', 'skill')) {
                $table->dropColumn('skill');
            }
            if (Schema::hasColumn('exam_categories', 'duration_min')) {
                $table->dropColumn('duration_min');
            }
            if (Schema::hasColumn('exam_categories', 'max_score')) {
                $table->dropColumn('max_score');
            }
            if (Schema::hasColumn('exam_categories', 'min_pass_percent')) {
                $table->dropColumn('min_pass_percent');
            }
            if (Schema::hasColumn('exam_categories', 'time_policy')) {
                $table->dropColumn('time_policy');
            }
        });

        // Drop v2 questions table
        Schema::dropIfExists('questions');

        // Recreate old questions table
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('exam_id');
            $table->enum('type', ['MCQ', 'TEXT'])->default('MCQ');
            $table->text('prompt');
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
        });
    }
};
