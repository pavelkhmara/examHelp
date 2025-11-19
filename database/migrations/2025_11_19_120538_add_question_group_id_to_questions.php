<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds question_group_id column to questions table for linking questions to groups.
     * Nullable for backward compatibility - existing questions continue to work.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedBigInteger('question_group_id')->nullable()->after('section_id');

            $table->foreign('question_group_id', 'fk_question_group')
                ->references('id')
                ->on('question_groups')
                ->onDelete('set null');

            $table->index('question_group_id', 'idx_question_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign('fk_question_group');
            $table->dropIndex('idx_question_group');
            $table->dropColumn('question_group_id');
        });
    }
};
