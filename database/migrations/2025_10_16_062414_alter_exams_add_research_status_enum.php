<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $enum = [
        'queued',
        'running_overview',
        'completed',
        'failed',
    ];

    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'research_status')) {
                $table->enum('research_status', $this->enum)
                      ->default('queued')
                      ->after('meta');
            }
        });

        DB::statement("UPDATE exams SET research_status='queued' WHERE research_status IS NULL OR research_status NOT IN ('queued','running_overview','completed','failed')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'research_status')) {
                $table->dropColumn('research_status');
            }
        });
    }
};
