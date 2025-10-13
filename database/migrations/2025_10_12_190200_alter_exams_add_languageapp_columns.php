<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('exams', function (Blueprint $table) {
            if (!Schema::hasColumn('exams', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('exams', 'sources')) {
                $table->json('sources')->nullable()->after('description');
            }
            if (!Schema::hasColumn('exams', 'meta')) {
                $table->json('meta')->nullable()->after('sources');
            }
            if (!Schema::hasColumn('exams', 'research_status')) {
                $table->enum('research_status', [
                    'queued','running_overview','running_categories','running_examples','running_rubrics','completed','failed'
                ])->default('queued')->index()->after('meta');
            }
            if (!Schema::hasColumn('exams', 'categories_count')) {
                $table->unsignedInteger('categories_count')->default(0)->after('research_status');
            }
            if (!Schema::hasColumn('exams', 'examples_count')) {
                $table->unsignedInteger('examples_count')->default(0)->after('categories_count');
            }
        });
    }

    public function down(): void {
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'examples_count')) $table->dropColumn('examples_count');
            if (Schema::hasColumn('exams', 'categories_count')) $table->dropColumn('categories_count');
            if (Schema::hasColumn('exams', 'research_status')) { 
                $table->dropIndex(['research_status']);
                $table->dropColumn('research_status');
            }
            if (Schema::hasColumn('exams', 'meta')) $table->dropColumn('meta');
            if (Schema::hasColumn('exams', 'sources')) $table->dropColumn('sources');
            if (Schema::hasColumn('exams', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
