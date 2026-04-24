# AI-Dev Codex Context

Este arquivo e o ponto de entrada operacional para qualquer sessao do Codex CLI iniciada em `/var/www/html/projetos/ai-dev`.

## Identidade do Projeto

- Projeto: AI-Dev.
- Raiz local: `/var/www/html/projetos/ai-dev`.
- Repositorio oficial: `github.com/italokandrade/ai-dev`.
- Remoto Git local: `git@github.com:italokandrade/ai-dev.git`.
- Aplicacao principal: `/var/www/html/projetos/ai-dev/ai-dev-core`.
- Stack principal: Laravel 13, TALL Stack, Livewire 4, Alpine.js, Tailwind CSS, Filament 5 e Anime.js.

## Escopo de Trabalho

- O `ai-dev-core` e o sistema master. Ele contem Filament, agentes, filas, tools, seguranca, logs, fluxo de PRD, modulos, tarefas e orquestracao de projetos alvo.
- Projetos alvo sao aplicacoes Laravel separadas operadas pelo `ai-dev-core`. Eles tem repositorio, banco, dependencias e Boost MCP proprios.
- O foco padrao deste workspace e trabalhar em `/var/www/html/projetos/ai-dev/ai-dev-core`.
- Nao analisar nem refatorar outros projetos alvo, exceto quando o usuario pedir explicitamente.
- Documentacao raiz em `.md` faz parte do produto e deve ser mantida coerente com o codigo.

## Documentacao Local

Antes de assumir APIs atuais da stack, consulte a documentacao local em `/var/www/html/projetos/ai-dev/docs_tecnicos`:

- `laravel13-docs`
- `livewire4-docs`
- `filament5-docs`
- `tailwind-docs`
- `alpine-docs`
- `animejs-docs`

Documentacao do projeto na raiz:

- `README.md`
- `ARCHITECTURE.md`
- `ADMIN_GUIDE.md`
- `INFRASTRUCTURE.md`
- `PRD-ai-dev-core.md`
- `PRD_SCHEMA.md`
- `PROMPTS.md`
- `STANDARD_MODULES.md`
- `FERRAMENTAS.md`
- `MIGRATION_LARAVEL13.md`
- `design.md`

Use `rg` nesses arquivos antes de criar novas regras de arquitetura, PRD, agentes, permissoes, logs ou fluxo de desenvolvimento.

## MCP e Laravel Boost

O MCP do Laravel Boost esta instalado no `ai-dev-core`.

- Configuracao local: `/var/www/html/projetos/ai-dev/ai-dev-core/.mcp.json`.
- Servidor MCP registrado: `laravel-boost`.
- Comando MCP: executar `php artisan boost:mcp` dentro de `/var/www/html/projetos/ai-dev/ai-dev-core`.
- Configuracao Laravel MCP: `ai-dev-core/config/mcp.php`.
- Pacote Boost: `laravel/boost`, declarado em `ai-dev-core/composer.json`.

No runtime do AI-Dev, a classe `App\Ai\Tools\BoostTool` envelopa o Laravel Boost via `php artisan boost:execute-tool` no `projects.local_path` do projeto alvo. Isso impede que o agente use contexto do projeto errado.

Tools Boost relevantes:

- `application-info`
- `search-docs`
- `database-schema`
- `database-query`
- `browser-logs`
- `last-error`

No chat do dashboard, o uso do Boost e mais restrito por seguranca.

## Fluxo de Produto

O fluxo esperado para projetos alvo e:

1. Descricao inicial do projeto e especificacoes backend/frontend.
2. PRD global.
3. Desenho de dominio antes dos modulos: entidades e relacionamentos em nivel alto, sem fechar campos cedo demais.
4. PRDs de modulos e submodulos.
5. Refinamento incremental do modelo: campos passam a ser definidos conforme os PRDs de modulo/submodulo amadurecem.
6. Criacao de tarefas.
7. Desenvolvimento somente depois que o planejamento estiver aprovado.

MER/ERD deve ser usado para o dominio de dados. Diagramas complementares podem ser usados quando ajudarem: fluxos, casos de uso, C4/arquitetura, sequencia/estado, BPMN ou ADRs enxutos.

## Areas Criticas Atuais

Seguranca, perfis, usuarios, logs e chat do dashboard precisam permanecer consistentes:

- Perfis e permissoes usam Filament Shield e Spatie Permission.
- Novas permissoes de superficies Filament devem ser sincronizadas automaticamente.
- Permissoes novas devem entrar habilitadas para o perfil `super_admin` e desabilitadas para os demais perfis.
- Logs de atividade usam Spatie Activitylog, `ActivityAuditService` e `SystemSurfaceMapService`.
- O mapeamento de logs deve enxergar automaticamente novas superficies do sistema, incluindo recursos, paginas, widgets, modulos e funcoes implementadas no futuro.
- O filtro de modulos nos logs nao deve duplicar opcoes.
- O chat do dashboard passa por `DashboardChat`, `SystemAssistantAgent`, `BoostTool` restrito e `FileReadTool` com bloqueios para arquivos sensiveis.

## Comandos Uteis

Use estes comandos a partir de `/var/www/html/projetos/ai-dev/ai-dev-core` quando relevantes:

- `php artisan test --compact`
- `./vendor/bin/pint <arquivos>`
- `php -l <arquivo>`
- `php artisan route:list --path=admin`
- `php artisan queue:monitor orchestrator`
- `php artisan horizon:status`
- `php artisan tinker --execute='...'`

Ao alterar codigo Filament/Laravel, rode testes focados e validacoes pequenas antes de encerrar.

## Regras de Edicao

- Preserve alteracoes nao relacionadas do usuario.
- Nao reverta submodules, arquivos de layout ou documentacao tecnica suja sem pedido explicito.
- Use os padroes existentes do Laravel, Filament e Livewire neste repositorio.
- Para UI, consulte `docs_tecnicos` antes de assumir sintaxe de Filament 5, Livewire 4, Tailwind, Alpine ou Anime.js.
- Para qualquer mudanca em documentacao arquitetural, mantenha `README.md`, `ARCHITECTURE.md`, `ADMIN_GUIDE.md`, `PRD-ai-dev-core.md`, `PRD_SCHEMA.md` e `FERRAMENTAS.md` coerentes quando o assunto atravessar esses arquivos.

## Skill Local

Existe uma skill local do projeto em `.codex/skills/ai-dev-project`. Use-a como referencia operacional adicional quando a sessao suportar skills locais ou quando o usuario pedir contexto do AI-Dev.
