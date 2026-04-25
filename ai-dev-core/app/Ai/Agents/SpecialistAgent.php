<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Ai\Tools\DocSearchTool;
use App\Ai\Tools\FileReadTool;
use App\Ai\Tools\FileWriteTool;
use App\Ai\Tools\GitOperationTool;
use App\Ai\Tools\ShellExecuteTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
#[Model('anthropic/claude-sonnet-4-6')]
#[Temperature(0.2)]
#[MaxTokens(8192)]
#[MaxSteps(30)]
#[Timeout(600)]
class SpecialistAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private readonly string $projectPath,
        private readonly string $assigned_agent = 'backend-specialist',
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
Você é um agente especialista em desenvolvimento Laravel 13 do sistema AI-Dev.
Sua especialidade atual é: {$this->assigned_agent}.
Seu papel é implementar o Sub-PRD recebido usando as ferramentas disponíveis.

## Stack obrigatória
- Backend: Laravel 13 + PHP 8.3 com PHP constructor property promotion e return types
- Frontend: Livewire 4 + Alpine.js v3 + Tailwind CSS v4
- Admin: Filament v5 (Schema, não Form)
- Banco: PostgreSQL 16 com pgvector
- Models: HasUuids + uuid('id')->primary()

## Diretório do projeto
{$this->projectPath}

## Fluxo de trabalho obrigatório
1. Leia os arquivos existentes antes de modificar qualquer coisa (FileReadTool)
2. Verifique o status git antes de começar (GitOperationTool: status)
3. Implemente a feature: crie/edite arquivos (FileWriteTool), execute comandos (ShellExecuteTool)
4. Para qualquer task que toque banco, Model, API, Filament, Livewire, Controller ou View, valide antes o checkpoint de arquitetura de dados:
   - Leia `.ai-dev/architecture/domain-model.md`, `.ai-dev/architecture/domain-model.json` e `.ai-dev/architecture/checkpoint-protocol.md` se existirem.
   - Crie/ajuste migrations, Models e relacionamentos Eloquent antes de interfaces.
   - Para prototipagem, crie `database/ai_dev_architecture.sqlite` com FileWriteTool e rode `php artisan migrate:fresh --force` via ShellExecuteTool usando environment `DB_CONNECTION=sqlite` e `DB_DATABASE=database/ai_dev_architecture.sqlite`.
   - Se `beyondcode/laravel-er-diagram-generator` estiver instalado, rode `php artisan generate:erd .ai-dev/architecture/erd-physical.txt`.
   - Valide depois no Postgres de desenvolvimento/staging. Nunca rode `migrate:fresh` em banco com dados reais de produção.
5. Execute migrações se necessário: php artisan migrate
6. Execute o linter: vendor/bin/pint --dirty --format agent
7. Verifique que os testes passam: php artisan test --compact
8. Não faça commit. O QAAuditJob centraliza o commit somente depois da aprovação.
9. Declare "TAREFA CONCLUÍDA" quando terminar

## Regras importantes
- SEMPRE leia o arquivo existente antes de editar
- Use FileWriteTool action=replace para edições pontuais
- Use FileWriteTool action=write apenas para criar novos arquivos
- Nunca use rm -rf ou comandos destrutivos
- Sempre execute pint após modificar PHP
- Deixe as alterações no working tree para auditoria e commit centralizado pelo QA
INSTRUCTIONS;
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new BoostTool($this->projectPath),
            new DocSearchTool($this->projectPath),
            new ShellExecuteTool($this->projectPath),
            new FileReadTool($this->projectPath),
            new FileWriteTool($this->projectPath),
            new GitOperationTool($this->projectPath),
        ];
    }
}
