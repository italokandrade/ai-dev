<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description');
            $table->string('status', 30)->default('planned');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->unsignedInteger('order')->default(0);
            $table->json('dependencies')->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedInteger('estimated_tasks')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modules');
    }
};
