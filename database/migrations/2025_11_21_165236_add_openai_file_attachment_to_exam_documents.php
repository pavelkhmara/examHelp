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
        Schema::table('exam_documents', function (Blueprint $table) {
            $table->string('openai_file_id')->nullable()->after('extracted_text');
            $table->string('file_attachment_status')->nullable()->after('openai_file_id'); // pending, uploaded, failed
            $table->timestamp('file_attached_at')->nullable()->after('file_attachment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_documents', function (Blueprint $table) {
            $table->dropColumn(['openai_file_id', 'file_attachment_status', 'file_attached_at']);
        });
    }
};
