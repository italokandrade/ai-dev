<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            // Dados do cliente/projeto
            $table->string('client_name');
            $table->string('project_name');
            $table->text('project_description')->nullable();

            // Complexidade: 1=Simples, 2=Médio, 3=Complexo, 4=Enterprise
            $table->unsignedTinyInteger('complexity_level')->default(2);

            // Áreas requeridas (JSON com booleanos)
            $table->json('required_areas')->nullable();

            // Horas estimadas por área (humanos)
            $table->unsignedInteger('backend_hours')->default(0);
            $table->unsignedInteger('frontend_hours')->default(0);
            $table->unsignedInteger('mobile_hours')->default(0);
            $table->unsignedInteger('database_hours')->default(0);
            $table->unsignedInteger('devops_hours')->default(0);
            $table->unsignedInteger('design_hours')->default(0);
            $table->unsignedInteger('testing_hours')->default(0);
            $table->unsignedInteger('security_hours')->default(0);
            $table->unsignedInteger('pm_hours')->default(0);

            // Urgência: 1=Normal, 2=Moderada, 3=Urgente, 4=Crítica
            $table->unsignedTinyInteger('urgency_level')->default(1);

            // Prazo de entrega em dias
            $table->unsignedInteger('delivery_days')->nullable();

            // Taxas horárias em BRL
            $table->decimal('hourly_rate_backend', 8, 2)->default(120.00);
            $table->decimal('hourly_rate_frontend', 8, 2)->default(110.00);
            $table->decimal('hourly_rate_mobile', 8, 2)->default(130.00);
            $table->decimal('hourly_rate_database', 8, 2)->default(115.00);
            $table->decimal('hourly_rate_devops', 8, 2)->default(125.00);
            $table->decimal('hourly_rate_design', 8, 2)->default(100.00);
            $table->decimal('hourly_rate_testing', 8, 2)->default(90.00);
            $table->decimal('hourly_rate_security', 8, 2)->default(140.00);
            $table->decimal('hourly_rate_pm', 8, 2)->default(130.00);

            // Multiplicadores
            $table->decimal('urgency_multiplier', 4, 2)->default(1.00);
            $table->decimal('complexity_multiplier', 4, 2)->default(1.00);

            // Resultados calculados
            $table->unsignedInteger('team_size')->default(1);
            $table->decimal('total_human_hours', 10, 2)->default(0);
            $table->decimal('total_human_cost', 12, 2)->default(0);
            $table->decimal('ai_dev_cost', 12, 2)->default(0);
            $table->decimal('savings_amount', 12, 2)->default(0);
            $table->decimal('savings_percentage', 5, 2)->default(0);
            $table->decimal('ai_dev_price', 12, 2)->default(0);

            // Custos reais de execução AI-Dev
            $table->decimal('actual_token_cost_usd', 10, 6)->default(0);
            $table->decimal('actual_infra_cost', 10, 2)->default(0);

            // Status: draft, sent, approved, rejected, in_progress, completed
            $table->string('status')->default('draft');

            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_quotations');
    }
};
