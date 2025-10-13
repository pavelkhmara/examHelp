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
    Schema::create('attempt_answers', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('attempt_id');
        $table->uuid('question_id');
        $table->uuid('selected_option_id')->nullable(); // для MCQ
        $table->text('text_answer')->nullable();        // для TEXT
        $table->boolean('is_correct')->nullable();
        $table->timestamps();

        $table->foreign('attempt_id')->references('id')->on('attempts')->cascadeOnDelete();
        $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
        $table->foreign('selected_option_id')->references('id')->on('question_options')->nullOnDelete();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempt_answers');
    }
};
