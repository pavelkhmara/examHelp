<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_logs', function (Blueprint $table) {
            // подберите тип под ваш PK в exams: uuid/ulid/integer
            $table->uuid('exam_id')->nullable()->index()->after('id');
        });

        $driver = DB::connection()->getDriverName();

        // Быстрый путь для MySQL/MariaDB
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('
                UPDATE generation_logs gl
                JOIN generation_tasks gt ON gt.id = gl.generation_task_id
                SET gl.exam_id = gt.exam_id
                WHERE gl.exam_id IS NULL
            ');
        } else {
            // Портируемый backfill без JOIN (SQLite, PgSQL и т.д.)
            DB::table('generation_logs')
                ->whereNull('exam_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $taskIds = collect($rows)->pluck('generation_task_id')->filter()->unique()->values()->all();
                    if (empty($taskIds)) {
                        return;
                    }
                    $map = DB::table('generation_tasks')
                        ->whereIn('id', $taskIds)
                        ->pluck('exam_id', 'id');

                    foreach ($rows as $r) {
                        $examId = $map[$r->generation_task_id] ?? null;
                        if ($examId) {
                            DB::table('generation_logs')
                                ->where('id', $r->id)
                                ->update(['exam_id' => $examId]);
                        }
                    }
                });
        }

        // (опционально) если хотите FK и у вас exams.id = UUID
        Schema::table('generation_logs', function (Blueprint $table) {
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generation_logs', function (Blueprint $table) {
            $table->dropForeign(['exam_id']);
            $table->dropColumn('exam_id');
        });
    }
};
