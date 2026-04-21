<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Models\SystemSetting;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

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
        // Se houver path, habilita o boost, senão envia sem tools para estabilidade
        if ($this->projectPath) {
            return [new BoostTool($this->projectPath)];
        }
        return [];
    }
}
