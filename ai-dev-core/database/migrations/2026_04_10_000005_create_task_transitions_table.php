<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 20); // 'task' or 'subtask'
            $table->uuid('entity_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('triggered_by', 50);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_transitions');
    }
};
