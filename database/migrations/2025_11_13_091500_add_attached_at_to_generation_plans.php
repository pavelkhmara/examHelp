<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('generation_plans', 'attached_at')) {
                $table->timestamp('attached_at')->nullable()->after('completed_at');
            }
        });

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE generation_plans MODIFY status ENUM('pending','in_progress','completed','failed','partial','attached') DEFAULT 'pending'"
            );
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TYPE generation_plans_status_enum ADD VALUE IF NOT EXISTS 'attached'");
        }
    }

    public function down(): void
    {
        Schema::table('generation_plans', function (Blueprint $table) {
            if (Schema::hasColumn('generation_plans', 'attached_at')) {
                $table->dropColumn('attached_at');
            }
        });

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE generation_plans MODIFY status ENUM('pending','in_progress','completed','failed','partial') DEFAULT 'pending'"
            );
        }
    }
};

