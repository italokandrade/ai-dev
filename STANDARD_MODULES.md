# Core Padrao dos Projetos Alvo

Este documento define a regra operacional para todo novo Projeto Alvo criado pelo AI-Dev.

Todo projeto novo nasce com dois blocos padrao herdados do `ai-dev-core`:

1. **Chatbox**
2. **Seguranca**

Esses blocos nao entram no planejamento de negocio do projeto. O `ProjectPrdAgent` nao deve pensar, decompor ou reescrever esses modulos no PRD de negocio. O sistema anexa automaticamente esses blocos em `projects.prd_payload.standard_modules`, cria os registros correspondentes em `project_modules` como `completed` e copia os arquivos base durante o scaffold pelo `/var/www/html/projetos/ai-dev/instalar_projeto.sh`.

## Chatbox

Modulo padrao de assistente conversacional do painel administrativo.

Arquivos base copiados do `ai-dev-core`:

- `app/Filament/Widgets/DashboardChat.php`
- `resources/views/filament/widgets/dashboard-chat.blade.php`
- `app/Ai/Agents/SystemAssistantAgent.php`
- `app/Ai/Tools/BoostTool.php`
- `app/Ai/Tools/FileReadTool.php`
- `app/Services/AiRuntimeConfigService.php`
- `app/Models/SystemSetting.php`
- migration de `system_settings`

Comportamento esperado:

- historico de conversa em sessao;
- auditoria de uso no `activity_log`;
- uso restrito de ferramentas de contexto;
- bloqueio de leitura de arquivos sensiveis;
- configuracao inicial de IA do sistema via `system_settings`.

## Seguranca

Bloco administrativo padrao equivalente ao bloco `Seguranca` do `ai-dev-core`.

Submodulos padrao:

- **Usuarios**: CRUD Filament de usuarios com associacao a perfis.
- **Perfis de Usuarios**: gestao de roles e permissoes via Filament Shield e Spatie Permission.
- **Logs de Atividades**: consulta auditavel de eventos via Spatie Activitylog.

Arquivos base copiados do `ai-dev-core`:

- `app/Models/User.php`
- `app/Filament/Resources/Users/**`
- `app/Filament/Resources/RoleResource.php`
- `app/Filament/Resources/ActivityLogs/**`
- `app/Services/ActivityAuditService.php`
- `app/Services/FilamentShieldPermissionSyncService.php`
- `app/Services/SystemSurfaceMapService.php`
- `config/filament-shield.php`
- `config/permission.php`
- migrations de permissoes e `activity_log`

Comportamento esperado:

- todo novo projeto ja possui usuarios, perfis, permissoes e logs;
- novas superficies Filament geram permissoes automaticamente;
- permissoes novas sao concedidas ao `super_admin`;
- demais perfis continuam sem permissoes novas ate configuracao manual;
- eventos de modelos e mudancas de permissao entram no `activity_log`.

## Regra de PRD

O PRD de projeto possui dois grupos:

- `modules`: modulos de negocio especificos do projeto, gerados pela IA.
- `standard_modules`: Chatbox e Seguranca, anexados automaticamente pelo sistema.

`Chatbox`, `Seguranca` e seus submodulos nao devem ser adicionados em `modules`.

## Regra de Banco do ai-dev-core

Ao cadastrar um projeto, o `StandardProjectModuleService` cria de forma idempotente:

- `Chatbox` como modulo raiz concluido;
- `Seguranca` como modulo raiz concluido;
- `Usuarios`, `Perfis de Usuarios` e `Logs de Atividades` como filhos concluidos de `Seguranca`.

Esses modulos recebem `prd_payload` e `blueprint_payload` com `source = ai_dev_core_standard`.

## Regra de Scaffold

O `/var/www/html/projetos/ai-dev/instalar_projeto.sh` instala dependencias, copia arquivos do `ai-dev-core`, ajusta providers, roda migrations e atribui o usuario inicial ao perfil `super_admin`.

O scaffold tambem instala e configura a base que os agentes de desenvolvimento usam depois:

- `laravel/ai`;
- `laravel/mcp`;
- `laravel/boost`;
- `config/ai.php`;
- `config/mcp.php`;
- `.mcp.json` individual do Projeto Alvo apontando para `php artisan boost:mcp`;
- migrations do Laravel AI SDK.

Cada Projeto Alvo tem seu proprio Boost MCP. Os agentes do `ai-dev-core` sempre consultam o Boost do projeto correto via `projects.local_path`, da mesma forma que cada projeto possui seu proprio repositorio GitHub registrado em `projects.github_repo`.

Antes de aprovar Blueprint, criar modulos ou iniciar a cascata, o ai-dev-core valida se o scaffold do alvo existe e contem `artisan`, `composer.json`, `.mcp.json`, `config/ai.php` e `config/mcp.php`. Se faltar algum arquivo, o projeto fica como `scaffold_failed` e nao gera modulos/tasks ate o scaffold ser corrigido.

Os agentes de desenvolvimento devem tratar esses blocos como base preexistente do Projeto Alvo. Alteracoes estruturais nesses blocos devem ser feitas primeiro no `ai-dev-core` e depois replicadas pelo scaffold padrao.
