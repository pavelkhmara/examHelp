<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generation_logs', function (Blueprint $table) {
            // подберите тип под ваш PK в exams: uuid/ulid/integer
            $table->uuid('exam_id')->nullable()->index()->after('id');
        });

        // Если логи связаны с тасками, а у тасков уже есть exam_id — заполним из них
        DB::statement("
            UPDATE generation_logs gl
            JOIN generation_tasks gt ON gt.id = gl.generation_task_id
            SET gl.exam_id = gt.exam_id
            WHERE gl.exam_id IS NULL
        ");

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