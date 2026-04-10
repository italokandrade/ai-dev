<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adicionar commit_hash nas subtasks para rastreabilidade e rollback
        Schema::table('subtasks', function (Blueprint $table) {
            $table->string('commit_hash', 40)->nullable()->after('result_diff');
        });

        // Adicionar commit_hash nas tasks (hash final após merge de todas subtasks)
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('commit_hash', 40)->nullable()->after('git_branch');
            // Flag para permitir re-execução da mesma task (redo) em vez de criar nova
            $table->boolean('is_redo')->default(false)->after('source');
            // ID da task original quando é um redo
            $table->uuid('original_task_id')->nullable()->after('is_redo');

            $table->foreign('original_task_id')
                ->references('id')
                ->on('tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            $table->dropColumn('commit_hash');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['original_task_id']);
            $table->dropColumn(['commit_hash', 'is_redo', 'original_task_id']);
        });
    }
};
