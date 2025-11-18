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
        Schema::table('exam_example_questions', function (Blueprint $table) {
            // Image fields for AI-generated visual content
            $table->text('image_url')->nullable()->after('assessment_guide'); // Primary image URL (winner)
            $table->text('image_file_path')->nullable()->after('image_url'); // Local file path (if downloaded)
            $table->json('image_alternatives')->nullable()->after('image_file_path'); // Top-3 alternatives (test mode)
            $table->json('image_metadata')->nullable()->after('image_alternatives'); // Provider, author, license, scores
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_example_questions', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'image_file_path', 'image_alternatives', 'image_metadata']);
        });
    }
};
