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
        Schema::create('generation_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // research_overview|research_categories|research_examples|research_rubrics|evaluation
            $table->nullableMorphs('subject'); // subject_type, subject_id
            $table->enum('status', ['queued','running','completed','failed'])->default('queued')->index();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['type','status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_tasks');
    }
};
