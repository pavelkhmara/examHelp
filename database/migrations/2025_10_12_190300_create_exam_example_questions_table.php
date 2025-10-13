<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('exam_example_questions', function (Blueprint $table) {
            $table->increments('id'); // INT UNSIGNED PK
            $table->char('exam_id', 36);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();

            $table->unsignedInteger('exam_category_id');
            $table->foreign('exam_category_id')->references('id')->on('exam_categories')->cascadeOnDelete();
            
            $table->text('question');
            $table->json('good_answer')->nullable();
            $table->json('average_answer')->nullable();
            $table->json('bad_answer')->nullable();
            $table->json('rubric_breakdown')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'exam_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_example_questions');
    }
};
