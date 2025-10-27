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
            // Metadata for rich example display
            $table->text('description')->nullable()->after('question'); // Описание задания
            $table->unsignedInteger('duration_minutes')->nullable()->after('description'); // Отведенное время
            $table->text('instructions')->nullable()->after('duration_minutes'); // Инструкции по выполнению
            $table->json('example_response')->nullable()->after('instructions'); // Пример выполнения
            $table->text('assessment_guide')->nullable()->after('rubric_breakdown'); // Комментарии по оценке
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_example_questions', function (Blueprint $table) {
            $table->dropColumn(['description', 'duration_minutes', 'instructions', 'example_response', 'assessment_guide']);
        });
    }
};
