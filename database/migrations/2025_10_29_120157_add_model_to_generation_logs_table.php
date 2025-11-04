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
        Schema::table('generation_logs', function (Blueprint $table) {
            $table->string('model')->nullable()->after('stage')->comment('AI model used (e.g., gpt-5-mini, o4-mini)');
            $table->string('model_alias')->nullable()->after('model')->comment('Model alias used (e.g., thinking, default)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_logs', function (Blueprint $table) {
            $table->dropColumn(['model', 'model_alias']);
        });
    }
};
