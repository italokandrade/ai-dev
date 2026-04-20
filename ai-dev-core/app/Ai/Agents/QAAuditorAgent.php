<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openai_dev')]
#[Model('gpt-5.3-codex')]
#[Temperature(0.1)]
#[MaxTokens(2048)]
#[Timeout(120)]
class QAAuditorAgent implements Agent, HasTools
{
    use Promptable;

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

## Formato de resposta (APENAS JSON, sem markdown):
{
  "approved": true,
  "overall_quality": "good",
  "issues": [],
  "summary": "Breve resumo do que foi implementado"
}

Ou quando rejeitado:
{
  "approved": false,
  "overall_quality": "poor",
  "issues": ["Descrição específica do problema"],
  "summary": "O que foi ou não foi feito"
}
INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new BoostTool,
        ];
    }
}
