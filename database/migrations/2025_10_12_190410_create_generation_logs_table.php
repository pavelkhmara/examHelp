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
        Schema::create('generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_task_id')->constrained()->cascadeOnDelete();
            $table->string('stage')->nullable();   // overview|categories|examples|rubrics|evaluation
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();

            $table->index(['generation_task_id','stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_logs');
    }
};
