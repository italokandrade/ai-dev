<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('title', 500);
            $table->json('sub_prd_payload');
            $table->string('status', 30)->default('pending');
            $table->string('assigned_agent', 50);
            $table->json('dependencies')->nullable();
            $table->unsignedInteger('execution_order')->default(1);
            $table->text('result_log')->nullable();
            $table->text('result_diff')->nullable();
            $table->json('files_modified')->nullable();
            $table->json('file_locks')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);
            $table->text('qa_feedback')->nullable();
            $table->timestamps();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['task_id', 'execution_order']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');
    }
};
