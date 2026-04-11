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
        Schema::table('project_modules', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'order']);
            $table->string('priority', 20)->default('normal')->change();
            $table->dropColumn('order');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priority', 20)->default('normal')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(50)->change();
            $table->unsignedInteger('order')->default(0)->after('priority');
            $table->index(['project_id', 'order']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(50)->change();
        });
    }
};
