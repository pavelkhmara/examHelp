<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->increments('id'); // INT UNSIGNED PK

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            
            $table->char('exam_id', 36);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            
            $table->unsignedInteger('exam_category_id')->nullable();
            $table->foreign('exam_category_id')->references('id')->on('exam_categories')->nullOnDelete();

            $table->text('answer');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['user_id','exam_id','created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
