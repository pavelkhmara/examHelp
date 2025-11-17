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
            // Поле для хранения пути к сгенерированному аудио файлу (если был сгенерирован)
            // Используется для кэширования и повторного использования
            $table->string('audio_file_path', 500)->nullable()->after('status');

            // Флаг указывающий что для этого вопроса нужно генерировать аудио
            $table->boolean('requires_audio')->default(false)->after('audio_file_path');

            // Индекс для быстрого поиска вопросов требующих генерации аудио
            $table->index(['requires_audio', 'audio_file_path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['requires_audio', 'audio_file_path']);
            $table->dropColumn(['audio_file_path', 'requires_audio']);
        });
    }
};
