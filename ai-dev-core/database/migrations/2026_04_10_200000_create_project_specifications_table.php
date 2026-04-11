<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_specifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->text('user_description');
            $table->json('ai_specification')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
            $table->index(['project_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_specifications');
    }
};
