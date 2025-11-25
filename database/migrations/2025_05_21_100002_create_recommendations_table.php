<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->string('type'); // weakness | strength | suggestion | resource
            $table->string('category')->nullable(); // Category/topic name
            $table->unsignedInteger('category_id')->nullable(); // FK to exam_categories
            $table->string('priority')->default('medium'); // high | medium | low
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('data')->nullable(); // Additional structured data
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('exam_categories')->nullOnDelete();
            $table->index(['session_id', 'type']);
            $table->index(['session_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
