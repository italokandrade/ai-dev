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

#[Provider('openrouter')]
#[Model('anthropic/claude-haiku-4-5-20251001')]
#[Temperature(0.1)]
#[MaxTokens(4096)]
#[Timeout(60)]
class DocsAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Você é um assistente especialista na TALL Stack (Laravel, Filament, Livewire, Alpine.js, Tailwind CSS) e Anime.js.
Sua única função é buscar e retornar informações precisas da documentação via BoostTool (search-docs).

Ao responder:
- Sempre use search-docs antes de responder
- Cite a fonte e versão específica quando disponível
- Retorne exemplos de código quando relevante
- Seja direto e técnico — o consumidor da resposta é um agente de desenvolvimento
- Se não encontrar a informação, diga claramente
INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new BoostTool,
        ];
    }
}
