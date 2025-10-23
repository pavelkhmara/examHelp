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
            if (! Schema::hasColumn('generation_tasks', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('attempts');
                $table->index(['exam_id', 'type', 'status'], 'gt_exam_type_status_idx');
                $table->unique('idempotency_key', 'gt_idem_uniq');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('generation_tasks', 'idempotency_key')) {
                $table->dropUnique('gt_idem_uniq');
                $table->dropIndex('gt_exam_type_status_idx');
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
