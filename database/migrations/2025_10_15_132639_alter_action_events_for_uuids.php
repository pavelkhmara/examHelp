<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Меняем типы ID-полей на UUID-совместимые.
        // Если таблица пуста, это пройдет без боли. Если нет — убедись, что там нет нужных данных.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE action_events MODIFY actionable_id CHAR(36) NOT NULL");
            DB::statement("ALTER TABLE action_events MODIFY target_id     CHAR(36) NULL");
            DB::statement("ALTER TABLE action_events MODIFY model_id      CHAR(36) NULL");
            // batch_id в Nova уже строка, user_id остается BIGINT.
        }
    }

    public function down(): void
    {
        // Откат к BIGINT (как по умолчанию у Nova)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE action_events MODIFY actionable_id BIGINT UNSIGNED NOT NULL");
            DB::statement("ALTER TABLE action_events MODIFY target_id     BIGINT UNSIGNED NULL");
            DB::statement("ALTER TABLE action_events MODIFY model_id      BIGINT UNSIGNED NULL");
        }
    }
};
