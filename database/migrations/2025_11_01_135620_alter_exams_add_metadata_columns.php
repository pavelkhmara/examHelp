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
        Schema::table('exams', function (Blueprint $table) {
            // User input fields
            if (! Schema::hasColumn('exams', 'user_input')) {
                $table->text('user_input')->nullable()->after('description');
            }
            if (! Schema::hasColumn('exams', 'user_meta')) {
                $table->json('user_meta')->nullable()->after('user_input');
            }

            // Identity information (replaces meta['exam_identity'])
            if (! Schema::hasColumn('exams', 'identity')) {
                $table->json('identity')->nullable()->after('user_meta');
            }

            // System analysis results
            if (! Schema::hasColumn('exams', 'system_analysis')) {
                $table->json('system_analysis')->nullable()->after('identity');
            }

            // Analysis status for async processing
            if (! Schema::hasColumn('exams', 'analysis_status')) {
                $table->enum('analysis_status', [
                    'pending', 'running', 'completed', 'failed',
                ])->default('pending')->after('system_analysis');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'analysis_status')) {
                $table->dropColumn('analysis_status');
            }
            if (Schema::hasColumn('exams', 'system_analysis')) {
                $table->dropColumn('system_analysis');
            }
            if (Schema::hasColumn('exams', 'identity')) {
                $table->dropColumn('identity');
            }
            if (Schema::hasColumn('exams', 'user_meta')) {
                $table->dropColumn('user_meta');
            }
            if (Schema::hasColumn('exams', 'user_input')) {
                $table->dropColumn('user_input');
            }
        });
    }
};
