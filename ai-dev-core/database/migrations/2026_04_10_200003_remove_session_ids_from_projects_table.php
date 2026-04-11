<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['gemini_session_id', 'claude_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('gemini_session_id')->nullable()->after('local_path');
            $table->string('claude_session_id')->nullable()->after('gemini_session_id');
        });
    }
};
