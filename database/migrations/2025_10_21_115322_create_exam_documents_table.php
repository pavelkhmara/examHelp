<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_documents', function (Blueprint $table) {
            $table->char('id', 36)->primary(); // UUID
            $table->char('exam_id', 36);
            $table->unsignedBigInteger('generation_task_id')->nullable();

            $table->string('original_name')->nullable();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->enum('status', ['uploaded', 'extracting', 'completed', 'failed'])->default('uploaded');
            $table->longText('extracted_text')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['exam_id']);
            $table->index(['status']);

            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            // generation_task_id ссылается на generation_tasks.id (int)
            $table->foreign('generation_task_id')->references('id')->on('generation_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_documents');
    }
};
