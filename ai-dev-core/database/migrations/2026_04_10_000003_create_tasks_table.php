<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 500);
            $table->json('prd_payload');
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->string('assigned_agent_id', 50)->nullable();
            $table->string('git_branch', 100)->nullable();
            $table->string('last_session_id')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);
            $table->text('error_log')->nullable();
            $table->string('source', 30)->default('manual');
            $table->timestamps();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreign('assigned_agent_id')
                ->references('id')
                ->on('agents_config')
                ->nullOnDelete();

            $table->index(['status', 'priority']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
