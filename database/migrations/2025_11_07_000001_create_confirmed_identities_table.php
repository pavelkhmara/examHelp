<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Таблица для хранения подтверждённой идентичности экзамена.
     * Отдельная запись позволяет отслеживать, что Identity был подтверждён
     * и не требует повторного запуска, пока не изменились влияющие поля.
     */
    public function up(): void
    {
        Schema::create('confirmed_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('exam_id')->index();
            $table->json('identity_data'); // Подтверждённая информация об экзамене
            $table->json('source_fields'); // Какие поля экзамена использовались (для отслеживания изменений)
            $table->timestamp('confirmed_at'); // Когда была подтверждена идентичность
            $table->unsignedBigInteger('confirmed_by_task_id')->nullable(); // ID GenerationTask, который подтвердил
            $table->boolean('is_valid')->default(true); // Инвалидируется при изменении влияющих полей
            $table->timestamps();

            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            $table->foreign('confirmed_by_task_id')->references('id')->on('generation_tasks')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('confirmed_identities');
    }
};
