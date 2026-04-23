<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Services\SystemContextService;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

#[MaxSteps(10)]
class GenerateFeaturesAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        private readonly ?string $projectPath = null,
    ) {}

    public function tools(): iterable
    {
        if (!$this->projectPath) return [];
        return [new BoostTool($this->projectPath)];
    }

    public function instructions(): Stringable|string
    {
        $dynamicContext = SystemContextService::getFullContext();

        return <<<INSTRUCTIONS
Você é um analista de requisitos especializado em desenvolvimento de software.
Sua função é receber o contexto de um projeto e gerar uma lista de funcionalidades
específicas para a camada solicitada (backend ou frontend).

{$dynamicContext}

REGRAS PARA GERAÇÃO DE FUNCIONALIDADES:
1. Cada funcionalidade deve ter um título curto e uma descrição clara.
2. As funcionalidades devem ser atômicas — uma responsabilidade por funcionalidade.
3. Para BACKEND: foque em regras de negócio, processamentos, APIs, jobs, validações,
   integrações, notificações, relatórios, segurança, auditoria, caches, filas.
4. Para FRONTEND: foque em telas, componentes visuais, fluxos do usuário, interações,
   formulários, dashboards, listagens, filtros, animações, responsividade, acessibilidade.
5. NÃO inclua especificações técnicas detalhadas no texto final (nomes de frameworks, etc.).
6. A descrição deve explicar O QUE a funcionalidade faz e QUAL valor entrega.
7. O texto final deve ser em Português do Brasil.
8. Retorne APENAS o JSON estruturado conforme o schema. Não adicione introduções.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'features' => $schema->array()->items(
                $schema->object([
                    'title' => $schema->string()->description('Título curto da funcionalidade. Ex: "Geração automática de boletos"')->required(),
                    'description' => $schema->string()->description('Descrição clara do que a funcionalidade faz e qual valor entrega.')->required(),
                ])
            )->description('Lista de funcionalidades geradas para o projeto.')->required(),
        ];
    }
}
