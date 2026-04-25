<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
#[Model('anthropic/claude-opus-4.7')]
#[Temperature(0.3)]
#[MaxTokens(8192)]
#[Timeout(180)]
class OrchestratorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Você é o Orchestrator do sistema AI-Dev, responsável por decompor um PRD (Product Requirement Document) de uma task em sub-PRDs atômicos que serão executados por agentes especialistas.

## Sua função
Receber o PRD de uma task e decompô-lo em Sub-PRDs independentes e executáveis.

## Regras de decomposição
1. Cada Sub-PRD deve ter uma responsabilidade única e escopo limitado
2. Um agente especialista deve conseguir executar cada Sub-PRD em uma única sessão
3. Defina dependências (pelo campo `execution_order`) entre Sub-PRDs quando necessário
4. Atribua o agente mais adequado: "backend-specialist", "frontend-specialist", "fullstack-specialist", "devops-specialist"
5. Liste os arquivos que serão modificados em `files` para controle de concorrência
6. Sub-PRDs independentes podem ser executados em paralelo (mesmo execution_order)
7. Se o PRD tiver `architecture_checkpoint.required=true` ou tocar banco/Model/API/Filament, crie primeiro uma Sub-PRD de arquitetura de dados para validar migrations, Models, relacionamentos Eloquent, SQLite temporário, ERD/Mermaid e Postgres de desenvolvimento.
8. Sub-PRDs de Filament, Livewire, Controllers, APIs ou Views devem depender da Sub-PRD de arquitetura quando o checkpoint for obrigatório.
9. O checkpoint deve produzir ou conferir `.ai-dev/architecture/domain-model.*` e, quando o pacote estiver instalado, `.ai-dev/architecture/erd-physical.txt`.

## Stack obrigatória
- Backend: Laravel 13 + PHP 8.3
- Frontend: Livewire 4 + Alpine.js v3 + Tailwind CSS v4
- Admin: Filament v5
- Banco: PostgreSQL 16
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->array()->items(
                $schema->object([
                    'execution_order' => $schema->integer()->description('Order of execution. Independent tasks can have the same order.')->required(),
                    'title' => $schema->string()->description('Short and descriptive title for the subtask.')->required(),
                    'assigned_agent' => $schema->string()->description('The specialist agent to assign: backend-specialist, frontend-specialist, fullstack-specialist, devops-specialist.')->required(),
                    'objective' => $schema->string()->description('Clear implementation goal.')->required(),
                    'acceptance_criteria' => $schema->array()->items($schema->string())->description('List of criteria for completion.')->required(),
                    'constraints' => $schema->array()->items($schema->string())->description('Technical constraints.')->required(),
                    'context' => $schema->string()->description('Additional technical context.')->required(),
                    'files' => $schema->array()->items($schema->string())->description('List of files to be modified/created.')->required(),
                    'dependencies' => $schema->array()->items($schema->string())->description('List of subtask titles that this subtask depends on.')->required(),
                ])
            )->required(),
        ];
    }
}
