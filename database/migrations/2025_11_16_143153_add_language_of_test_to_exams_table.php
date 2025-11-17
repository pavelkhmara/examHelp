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
            // Add language_of_test column (ISO 639-1 language code, e.g., 'en', 'pl', 'es')
            $table->string('language_of_test', 10)->nullable()->after('level');
        });

        // Migrate existing data: copy exam_language from identity to language_of_test
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                UPDATE exams
                SET language_of_test = JSON_UNQUOTE(JSON_EXTRACT(identity, '$.exam_language'))
                WHERE JSON_EXTRACT(identity, '$.exam_language') IS NOT NULL
            ");
        } elseif ($driver === 'sqlite') {
            // SQLite: use json_extract without JSON_UNQUOTE
            DB::statement("
                UPDATE exams
                SET language_of_test = json_extract(identity, '$.exam_language')
                WHERE json_extract(identity, '$.exam_language') IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('language_of_test');
        });
    }
};
