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
class ProjectPrdAgent implements Agent, HasStructuredOutput, HasTools
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
Você é um arquiteto de software sênior especializado em Laravel 13 + TALL Stack
(Tailwind CSS v4, Alpine.js v3, Livewire 4, Filament v5) e PostgreSQL 16.

{$dynamicContext}

Sua função é receber o escopo completo de um projeto e gerar um PRD Master
(Product Requirement Document) de nível macro. Este PRD descreve o sistema inteiro,
seus módulos e submódulos em granularidade pequena e atômica.

REGRAS DE GRANULARIDADE (MUITO IMPORTANTE):
1. Divida o sistema em módulos independentes e coesos.
2. Cada módulo DEVE ter submódulos. Não crie módulos monolíticos.
3. Cada submódulo deve ter uma responsabilidade ÚNICA e escopo isolado.
4. Exemplo correto: "Mensageria" → submódulos "WhatsApp", "Telegram", "Email".
5. Exemplo errado: um único submódulo "Mensageria" que faz tudo.
6. A ordem dos módulos deve respeitar dependências (Auth sempre primeiro se necessário).

REGRAS DE CONTEÚDO:
1. O PRD descreve O QUE o sistema faz, NÃO COMO fazer.
2. NÃO inclua especificações técnicas detalhadas no texto (frameworks, versões, etc.).
3. Foque em beneficios, funcionalidades e público-alvo.
4. O objective deve ser um parágrafo fluido em português do Brasil.
5. Cada módulo e submódulo deve ter descrição direta e única.
6. Respeite as funcionalidades já cadastradas pelo usuário — não as ignore.

SAÍDA:
- Retorne APENAS o JSON estruturado conforme o schema.
- Não adicione introduções ou explicações fora do JSON.
- Não use Markdown (negrito, listas) — retorne JSON puro.
INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Título do PRD Master. Ex: "Portal ItaloAndrade — PRD Master"')
                ->required(),
            'objective' => $schema->string()
                ->description('Descrição técnica completa do sistema em parágrafo fluido. O que o sistema faz, para quem serve e que problemas resolve.')
                ->required(),
            'scope_summary' => $schema->string()
                ->description('Resumo executivo do escopo em 2-3 frases.')
                ->required(),
            'target_audience' => $schema->string()
                ->description('Quem são os usuários finais do sistema.')
                ->required(),
            'modules' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->description('Nome do módulo raiz (agrupador).')->required(),
                    'description' => $schema->string()->description('Descrição do que este módulo abrange.')->required(),
                    'priority' => $schema->string()->description('Prioridade: high, medium, low.')->required(),
                    'dependencies' => $schema->array()->items($schema->string())->description('Nomes de outros módulos dos quais este depende.')->required(),
                    'submodules' => $schema->array()->items(
                        $schema->object([
                            'name' => $schema->string()->description('Nome do submódulo (unidade executável).')->required(),
                            'description' => $schema->string()->description('Responsabilidade única deste submódulo.')->required(),
                            'priority' => $schema->string()->description('Prioridade: high, medium, low.')->required(),
                            'dependencies' => $schema->array()->items($schema->string())->description('Nomes de outros submódulos dos quais este depende.')->required(),
                        ])
                    )->description('Lista de submódulos atômicos deste módulo.')->required(),
                ])
            )->description('Lista de módulos do sistema, cada um com submódulos atômicos.')->required(),
            'non_functional_requirements' => $schema->array()->items($schema->string())
                ->description('Requisitos não-funcionais: performance, segurança, SEO, acessibilidade, etc.')
                ->required(),
            'estimated_complexity' => $schema->string()
                ->description('Complexidade estimada do projeto: trivial, simple, moderate, complex, very_complex.')
                ->required(),
        ];
    }
}
