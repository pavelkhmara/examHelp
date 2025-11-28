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
        Schema::create('generation_plans', function (Blueprint $table) {
            $table->id();

            // Foreign key to exam (UUID)
            $table->uuid('exam_id');
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');

            // Section identifier from structure_v2
            $table->string('section_id'); // e.g., "sec-listening", "sec-reading"

            // Assembly mode from Phase B
            $table->enum('assembly_mode', ['pool', 'blueprint', 'inline']);

            // Plan data (JSON) - detailed generation plan
            // For pool: { pool_id, filters, pick, seed }
            // For blueprint: { slots: [{ slot_id, from_pool, pick, filters }] }
            // For inline: { placeholders: [{ id, type }] }
            $table->json('plan_data');

            // Generation status
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'partial'])
                ->default('pending');

            // Question generation tracking
            $table->integer('total_questions')->default(0); // Expected total
            $table->integer('generated_questions')->default(0); // Actually generated

            // Error tracking
            $table->text('error')->nullable();

            // Metadata
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('exam_id');
            $table->index('section_id');
            $table->index('status');
            $table->unique(['exam_id', 'section_id']); // One plan per section per exam
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_plans');
    }
};
