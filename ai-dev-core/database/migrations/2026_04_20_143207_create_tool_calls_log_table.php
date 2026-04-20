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
        Schema::create('tool_calls_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invocation_id')->nullable()->index();
            $table->string('agent_class')->nullable();
            $table->string('tool_class')->nullable();
            $table->json('arguments')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_calls_log');
    }
};
