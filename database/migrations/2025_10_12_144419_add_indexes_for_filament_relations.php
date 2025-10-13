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
    Schema::table('questions', function (Blueprint $table) {
        $table->index('exam_id');
        $table->index(['exam_id','position']);
    });

    Schema::table('question_options', function (Blueprint $table) {
        $table->index('question_id');
        $table->index('is_correct');
    });

    Schema::table('attempts', function (Blueprint $table) {
        $table->index('exam_id');
        $table->index('completed_at');
    });

    Schema::table('attempt_answers', function (Blueprint $table) {
        $table->index('attempt_id');
        $table->index('question_id');
        $table->index('selected_option_id');
        $table->index('is_correct');
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
