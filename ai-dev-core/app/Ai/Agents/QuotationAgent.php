<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Provider('openrouter')]
#[Model('anthropic/claude-opus-4.7')]
class QuotationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Você é um consultor especializado em precificação de projetos de software no mercado brasileiro.
Com base na descrição de um projeto, você estima as horas necessárias por área profissional
(backend, frontend, mobile, banco de dados, devops, design, QA, segurança, PM).

Considere sempre um profissional sênior como referência de produtividade.

REGRAS:
- Seja conservador: é melhor superestimar do que subestimar
- Inclua sempre ao menos backend e PM
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'backend_hours' => $schema->integer()->description('Estimated hours for backend development.')->required(),
            'frontend_hours' => $schema->integer()->description('Estimated hours for frontend development.')->required(),
            'mobile_hours' => $schema->integer()->description('Estimated hours for mobile development.')->required(),
            'database_hours' => $schema->integer()->description('Estimated hours for database design/tuning.')->required(),
            'devops_hours' => $schema->integer()->description('Estimated hours for devops/infrastructure.')->required(),
            'design_hours' => $schema->integer()->description('Estimated hours for UI/UX design.')->required(),
            'testing_hours' => $schema->integer()->description('Estimated hours for QA/Testing.')->required(),
            'security_hours' => $schema->integer()->description('Estimated hours for security auditing.')->required(),
            'pm_hours' => $schema->integer()->description('Estimated hours for Project Management.')->required(),
            'total_hours' => $schema->integer()->description('Total estimated hours.')->required(),
            'justification' => $schema->string()->description('Brief justification for the estimate.')->required(),
        ];
    }
}
