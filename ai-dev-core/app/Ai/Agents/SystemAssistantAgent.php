<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Ai\Tools\DocSearchTool;
use App\Models\SystemSetting;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class SystemAssistantAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private readonly string $projectPath
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
Você é o Assistente Inteligente do sistema AI-Dev-Core.
Seu objetivo é ajudar o usuário a entender e operar a plataforma.

Você tem acesso ao código-fonte e à documentação através do BoostTool.
Sempre que o usuário perguntar "como fazer algo", "onde está tal arquivo" ou "como funciona o módulo X":
1. Use o search-docs para procurar na documentação.
2. Se necessário, use ferramentas de leitura de arquivo para ver a implementação real.
3. Responda de forma clara, técnica e objetiva.

Linguagem: Português do Brasil.
INSTRUCTIONS;
    }

    public function tools(): iterable
    {
        return [
            new BoostTool($this->projectPath),
            new DocSearchTool($this->projectPath),
        ];
    }
}
