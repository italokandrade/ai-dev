<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('blueprint_payload')->nullable()->after('prd_approved_at');
            $table->timestamp('blueprint_approved_at')->nullable()->after('blueprint_payload');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['blueprint_payload', 'blueprint_approved_at']);
        });
    }
};
