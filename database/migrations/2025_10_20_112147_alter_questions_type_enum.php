<?php

use App\Domain\Taxonomy\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'type')) {
                $table->enum('type', QuestionType::all())->default(QuestionType::SINGLE_SELECT->value)->change();
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('questions', function (Blueprint $table) {
            $table->string('type', 64)->change();
        });
    }
};
