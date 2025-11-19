<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates question_groups table for grouping questions with shared stimulus.
     * Used for listening/reading tasks where multiple questions refer to same audio/text.
     */
    public function up(): void
    {
        Schema::create('question_groups', function (Blueprint $table) {
            $table->id();
            $table->char('exam_id', 36);
            $table->unsignedInteger('section_id');
            $table->string('group_id', 255);
            $table->string('title', 500)->nullable();
            $table->json('instructions')->nullable()->comment('Group-level instructions');
            $table->json('stimulus')->nullable()->comment('Shared stimulus (audio, video, text, images)');
            $table->json('playback_settings')->nullable()->comment('Playback controls (max_plays, enforcement)');
            $table->json('metadata')->nullable()->comment('Additional metadata');
            $table->unsignedInteger('order')->default(0)->comment('Display order within section');
            $table->timestamps();

            // Foreign keys
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            $table->foreign('section_id')->references('id')->on('exam_categories')->onDelete('cascade');

            // Indexes
            $table->index(['exam_id', 'section_id'], 'idx_exam_section');
            $table->index(['section_id', 'order'], 'idx_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_groups');
    }
};
