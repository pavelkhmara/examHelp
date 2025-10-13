<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('exam_categories', function (Blueprint $table) {
            $table->increments('id'); // INT UNSIGNED PK
            $table->char('exam_id', 36);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            
            $table->string('key')->index();
            $table->string('name');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['exam_id','key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_categories');
    }
};
