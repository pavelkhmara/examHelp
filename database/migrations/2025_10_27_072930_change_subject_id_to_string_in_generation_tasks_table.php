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
            // Change subject_id from integer to string to support UUID primary keys
            $table->string('subject_id', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_tasks', function (Blueprint $table) {
            // Revert to integer (may lose data if UUIDs were stored)
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
