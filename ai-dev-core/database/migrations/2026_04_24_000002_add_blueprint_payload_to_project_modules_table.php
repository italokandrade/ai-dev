<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->json('blueprint_payload')->nullable()->after('prd_payload');
        });
    }

    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->dropColumn('blueprint_payload');
        });
    }
};
