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
            $table->dropColumn(['estimated_tasks', 'acceptance_criteria']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->unsignedInteger('estimated_tasks')->nullable();
            $table->json('acceptance_criteria')->nullable();
        });
    }
};
