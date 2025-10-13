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
    Schema::create('attempts', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('exam_id');
        $table->uuid('user_id')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->unsignedInteger('score')->nullable(); // 0..100
        $table->timestamps();

        $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
