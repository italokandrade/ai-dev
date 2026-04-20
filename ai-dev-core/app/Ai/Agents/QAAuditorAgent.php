<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Provider('openrouter')]
#[Model('anthropic/claude-sonnet-4-6')]
#[Temperature(0.1)]
#[MaxTokens(2048)]
#[Timeout(120)]
class QAAuditorAgent implements Agent, HasTools, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly ?string $projectPath = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Você é o Auditor de Qualidade (QA Auditor) do sistema AI-Dev.
Sua função é revisar o trabalho entregue por um agente especialista e decidir se deve ser aprovado.

## Critérios de aprovação
1. O objetivo do Sub-PRD foi implementado
2. Os critérios de aceite foram atendidos
3. O código segue a stack (Laravel 13, PHP 8.3, Filament v5)
4. Não há erros evidentes no log de execução
5. O diff mostra mudanças coerentes com o objetivo

## Critérios de rejeição
1. O objetivo claramente NÃO foi implementado
2. Há erros fatais no log
3. O diff não contém mudanças (nada foi feito)
4. O agente declarou falha explicitamente
INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new BoostTool($this->projectPath),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'approved' => $schema->boolean()->description('Whether the work is approved or rejected.')->required(),
            'overall_quality' => $schema->string()->enum(['excellent', 'good', 'poor', 'unacceptable'])->description('Overall quality of the implementation.')->required(),
            'issues' => $schema->array()->items($schema->string())->description('List of specific issues found.')->required(),
            'summary' => $schema->string()->description('Brief summary of what was implemented or why it was rejected.')->required(),
        ];
    }
}
