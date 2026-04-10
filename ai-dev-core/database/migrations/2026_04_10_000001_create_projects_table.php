<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('github_repo', 255)->nullable();
            $table->string('local_path', 500);
            $table->string('gemini_session_id')->nullable();
            $table->string('claude_session_id')->nullable();
            $table->string('default_provider', 50)->default('gemini');
            $table->string('default_model', 100)->default('gemini-3.1-flash-lite-preview');
            $table->string('status', 30)->default('active');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
