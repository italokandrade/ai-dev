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
#[Model('anthropic/claude-3.5-sonnet')]
#[Temperature(0.3)]
#[MaxTokens(2048)]
#[Timeout(120)]
class SystemAssistantAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private readonly ?string $projectPath = null
    ) {}

    public function instructions(): Stringable|string
    {
        return "Você é o Assistente do AI-Dev. Responda perguntas sobre o sistema.";
    }

    public function tools(): iterable
    {
        if ($this->projectPath) {
            return [new BoostTool($this->projectPath)];
        }
        return [];
    }
}
