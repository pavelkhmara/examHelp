<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('question_id'); // FK to questions table
            $table->json('answer'); // User's answer (string | array | object depending on type)
            $table->json('evaluation')->nullable(); // Scoring result: correct, points, feedback
            $table->boolean('is_correct')->nullable(); // Quick lookup for correct answers
            $table->decimal('points_earned', 5, 2)->nullable();
            $table->decimal('points_possible', 5, 2)->nullable();
            $table->integer('time_spent_sec')->nullable(); // Time spent on this question
            $table->tinyInteger('attempt')->default(1); // Attempt number (for practice mode)
            $table->timestamps();

            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->unique(['session_id', 'question_id', 'attempt']);
            $table->index(['session_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
