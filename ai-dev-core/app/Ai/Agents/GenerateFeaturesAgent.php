<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Services\SystemContextService;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class GenerateFeaturesAgent implements Agent
{
    use Promptable;

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
8. Retorne APENAS um JSON válido no formato abaixo, sem markdown, sem introduções:

{"features":[{"title":"Título da funcionalidade","description":"Descrição clara"}]}
INSTRUCTIONS;
    }
}
