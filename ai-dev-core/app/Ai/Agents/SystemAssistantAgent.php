<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
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
        return "Você é o Assistente do AI-Dev. Responda em Português.";
    }

    public function tools(): iterable
    {
        if ($this->projectPath) {
            return [new BoostTool($this->projectPath)];
        }
        return [];
    }
}
