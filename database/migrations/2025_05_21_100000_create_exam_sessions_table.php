<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_id')->constrained()->cascadeOnDelete();
            $table->string('user_id')->nullable()->index(); // External user ID from mobile app
            $table->string('device_id')->nullable()->index(); // Device identifier
            $table->string('mode')->default('practice'); // practice | exam | review
            $table->json('settings')->nullable(); // time_limit, shuffle, category_ids, etc.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable(); // Final score, breakdown, stats
            $table->timestamps();

            $table->index(['exam_id', 'user_id']);
            $table->index(['started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
    }
};
