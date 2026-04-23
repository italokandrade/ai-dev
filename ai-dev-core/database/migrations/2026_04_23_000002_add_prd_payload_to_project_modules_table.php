<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->json('prd_payload')->nullable()->after('dependencies');
        });
    }

    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->dropColumn('prd_payload');
        });
    }
};
