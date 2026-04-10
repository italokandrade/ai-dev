<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents_config', function (Blueprint $table) {
            $table->string('id', 50)->primary(); // slug: orchestrator, qa-auditor, etc.
            $table->string('display_name', 100);
            $table->text('role_description');
            $table->string('provider', 50)->default('gemini');
            $table->string('model', 100)->default('gemini-3.1-flash-lite-preview');
            $table->string('api_key_env_var', 100)->default('GEMINI_API_KEY');
            $table->float('temperature')->default(0.4);
            $table->unsignedInteger('max_tokens')->default(8192);
            $table->json('knowledge_areas')->nullable();
            $table->unsignedInteger('max_parallel_tasks')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('fallback_agent_id', 50)->nullable();
            $table->timestamps();

            $table->foreign('fallback_agent_id')
                ->references('id')
                ->on('agents_config')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents_config');
    }
};
