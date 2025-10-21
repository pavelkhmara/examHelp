<?php

use App\Domain\Taxonomy\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_example_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_example_questions', 'type')) {
                if (Schema::getConnection()->getDriverName() === 'mysql') {
                    $table->enum('type', QuestionType::all())
                        ->default(QuestionType::SINGLE_SELECT->value);
                } else {
                    $table->string('type', 64)
                        ->default(QuestionType::SINGLE_SELECT->value);
                }
            }
            // PAYLOAD: тоже без after(), чтобы не ломаться на разных схемах
            if (! Schema::hasColumn('exam_example_questions', 'payload')) {
                $table->json('payload')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_example_questions', function (Blueprint $table) {
            if (Schema::hasColumn('exam_example_questions', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('exam_example_questions', 'payload')) {
                $table->dropColumn('payload');
            }
        });
    }
};
