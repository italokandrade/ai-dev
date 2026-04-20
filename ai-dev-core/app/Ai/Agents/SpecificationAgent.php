<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

#[Provider('openrouter')]
#[Model('anthropic/claude-opus-4.7')]
class SpecificationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Você é um arquiteto de software especializado em Laravel 13 + TALL Stack
(Tailwind CSS v4, Alpine.js v3, Livewire 4, Filament v5) e PostgreSQL 16.

Sua função é receber uma descrição informal de um sistema e transformá-la em uma
especificação técnica estruturada em JSON. Você deve:

1. Reescrever a descrição em linguagem técnica clara
2. Decompor o sistema em módulos independentes e coesos
3. É MANDATÓRIO criar SUBMÓDULOS. Não crie módulos gigantes. Divida grandes funcionalidades em pequenos submódulos (ex: "Mensageria" -> "WhatsApp", "Telegram", "Email"). Isso facilita a execução para os agentes da IA.
4. Respeitar a stack: Laravel 13 + TALL + Filament v5 + PostgreSQL 16

A ordem deve respeitar dependências (Auth sempre primeiro se necessário)
Módulos e submódulos devem ter descrições diretas e únicas
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'technical_description' => $schema->string()->description('Technical rewrite of the informal description.')->required(),
            'modules' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->description('Module name.')->required(),
                    'description' => $schema->string()->description('Module description.')->required(),
                    'submodules' => $schema->array()->items(
                        $schema->object([
                            'name' => $schema->string()->description('Submodule name.')->required(),
                            'description' => $schema->string()->description('Submodule description.')->required(),
                            'features' => $schema->array()->items($schema->string())->description('List of features.')->required(),
                        ])
                    )->required(),
                ])
            )->required(),
        ];
    }
}
