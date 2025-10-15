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
        Schema::table('generation_tasks', function (Blueprint $table) {
            // Добавляем exam_id если его нет
            if (!Schema::hasColumn('generation_tasks', 'exam_id')) {
                $table->char('exam_id', 36)->nullable()->after('id');
                $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_tasks', function (Blueprint $table) {
            $table->dropForeign(['exam_id']);
            $table->dropColumn('exam_id');
        });
    }
};
