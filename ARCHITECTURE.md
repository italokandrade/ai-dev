# Arquitetura do AI-Dev (AndradeItalo.ai)

## 1. Visão Geral da Arquitetura

O AI-Dev é um ecossistema de desenvolvimento de software autônomo, assíncrono e multi-agente, estritamente focado na stack TALL (Tailwind, Alpine.js, Laravel, Livewire) + Filament v5 + Anime.js. Ele opera em background (headless), guiado por um banco de dados relacional PostgreSQL e enriquecido por uma memória de longo prazo vetorial nativa (pgvector). As instruções trafegam em formato PRD (Product Requirement Document) para garantir clareza absoluta na comunicação entre os agentes.

**Componentes Fundamentais do Ecossistema:**

```text
┌──────────────────────────────────────────────────────────────────────┐
│                        AI-DEV CORE (Laravel 13)                      │
│                                                                      │
│  ┌────────────┐   ┌──────────────┐   ┌───────────────────────────┐  │
│  │ Filament v5 │   │  Agent        │   │   Tool Layer (MCP)        │  │
│  │ (Web UI)    │   │  instructions │   │   (Laravel AI SDK Tools)  │  │
│  └──────┬──────┘   └──────┬───────┘   └────────────┬──────────────┘  │
│         │                 │                        │                  │
│  ┌──────▼─────────────────▼────────────────────────▼──────────────┐  │
│  │                     PostgreSQL (Estado Central)                  │  │
│  │  projects │ tasks │ subtasks │ agents_config │ context_library  │  │
│  └──────────────────────┬────────────────────────────────────────┘  │
│                         │                                            │
│  ┌──────────────────────▼────────────────────────────────────────┐  │
│  │              Laravel Queue + Redis (Barramento)                │  │
│  │                                                                │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐ │  │
│  │  │ Orchestrator  │  │ QA Auditor   │  │ Subagentes Executor  │ │  │
│  │  │ (Planner Job) │  │ (Judge Job)  │  │ (Specialist Jobs)    │ │  │
│  │  └──────────────┘  └──────────────┘  └──────────────────────┘ │  │
│  └───────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐    │
│  │   Motores LLM                                                │    │
│  │   openrouter/claude-opus-4.7 (Planejamento)                   │    │
│  │   openrouter/claude-sonnet-4-6 (Código/QA)                   │    │
│  │   openrouter/claude-haiku-4-5-20251001 (Docs)                │    │
│  │   Ollama Local (Compressor — planejado, fase futura)          │    │
│  └──────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌───────────────────────┐  ┌───────────────────────────────────┐   │
│  │ pgvector (Embeddings  │  │ Sentinel (Self-Healing Runtime)   │   │
│  │ + Semantic Search)    │  │ (Exception Handler Customizado)   │   │
│  └───────────────────────┘  └───────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────┘
```

**Por que essa arquitetura e não outra?**
Sistemas multi-agente baseados em "prompt chains" livres (onde uma IA simplesmente chama outra sem controle) são frágeis e imprevisíveis. O AI-Dev elimina esse problema ao usar o PostgreSQL como **fonte da verdade única**: todo estado, toda transição e todo resultado ficam registrados em tabelas com constraints SQL. Não existe "estado na memória" — se o servidor reiniciar, o sistema retoma exatamente de onde parou lendo o banco.

> **Nota de leitura:** O diagrama acima representa o **ai-dev-core** (Master). Todos os componentes (Filament, agentes, pgvector, Sentinel) vivem dentro do ai-dev-core. O código-alvo que o `SpecialistAgent` lê e escreve, o Git que o `GitOperationTool` opera, e o Boost MCP que o `BoostTool` consulta ficam em **outra** aplicação Laravel — o **Projeto Alvo**. Veja a seção 1.A a seguir.

---

## 1.A. Arquitetura em Duas Camadas: ai-dev-core vs. Projeto Alvo

O AI-Dev opera sobre **duas classes distintas de aplicações Laravel**, cada uma com seu próprio ciclo de vida, repositório e Boost MCP. Entender essa separação é pré-requisito para ler qualquer outra seção deste documento.

- **ai-dev-core (Master)** — Esta aplicação Laravel 13 + Filament v5. Contém os agentes de desenvolvimento (`OrchestratorAgent`, `SpecialistAgent`, `QAAuditorAgent`, `DocsAgent`), as AIs de interação com o usuário do Admin Panel (`RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent`), as filas, o Boost MCP usado pelo Claude Code para manutenção do próprio ai-dev-core, e o banco `ai_dev_core` (projects, tasks, subtasks, agents_config, task_transitions, etc.).
- **Projeto Alvo** — Toda aplicação Laravel operada pelo ai-dev-core. Tem seu próprio repositório GitHub, seu próprio banco, suas próprias dependências, seu próprio Boost MCP instalado e pode ter suas próprias AIs de interação (definidas na spec de cada projeto). **Não tem agentes de desenvolvimento** — quem desenvolve é o ai-dev-core, consumindo o Boost do Projeto Alvo como fonte de contexto.

A tabela comparativa canônica — com repositório, codebase, banco, dependências, Boost, Admin Panel, AIs, workers e `.env` — vive em [`README.md` → seção "Arquitetura em Duas Camadas"](./README.md#-arquitetura-em-duas-camadas-authoritative). Este documento referencia aquela tabela e **não** a duplica.

### 1.A.1. Acoplamento entre Camadas

O ai-dev-core se conecta a cada Projeto Alvo por **dois vetores** e nada mais:

1. **Filesystem** — `projects.local_path` armazena o caminho absoluto (ex: `/var/www/html/projetos/portal`). `FileReadTool`, `FileWriteTool`, `ShellExecuteTool` e `GitOperationTool` recebem esse path no constructor e operam **exclusivamente** dentro dele.
2. **Boost MCP do Projeto Alvo** — o `BoostTool` envelopa `php artisan boost:execute-tool` executado **dentro** do `local_path` do alvo. Isso garante que `database-schema`, `search-docs`, `database-query`, `browser-logs` reflitam o estado real **daquele** projeto (inclusive versões de Laravel/Filament/Livewire instaladas, que podem divergir do ai-dev-core).

Nenhum estado do ai-dev-core vaza para o Projeto Alvo: o agente escreve código, commita no repositório do alvo, executa testes no alvo. A trilha de auditoria (quem fez o quê, tokens gastos, transições de status) fica registrada **somente** no banco do ai-dev-core.

### 1.A.2. Duas Classes de AIs

Para evitar confusão entre os vários papéis de IA no ecossistema:

| Papel | Onde vive | Quem usa | Exemplos |
|---|---|---|---|
| **IAs de Interação** *(falam com o usuário)* | Em **cada** aplicação Laravel — ai-dev-core tem as suas; cada Projeto Alvo tem as suas. | Usuários finais pelo Admin Panel dessa aplicação. | `RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent` (ai-dev-core); copiloto do usuário, classificador, sumarizador (Projeto Alvo). |
| **IAs de Desenvolvimento** *(escrevem código)* | **Exclusivamente** no ai-dev-core. | O próprio ai-dev-core, processando tasks na fila. Operam sobre o filesystem e o Boost do Projeto Alvo. | `OrchestratorAgent`, `SpecialistAgent`, `QAAuditorAgent`, `DocsAgent`. |

Projetos Alvo **nunca** instanciam agentes de desenvolvimento — desenvolvimento autônomo é a responsabilidade única do ai-dev-core.

### 1.A.3. Stack Compartilhada vs. Stack Divergente

A stack descrita no `README.md` (Laravel 13, Filament v5, Livewire 4, PostgreSQL 16 + pgvector, Redis 7, PHP 8.3) é simultaneamente:
- A stack interna **do próprio ai-dev-core**;
- A stack **default** que `instalar_projeto.sh` provisiona para novos Projetos Alvo.

Projetos Alvo podem divergir (ex: pinar Filament v5.3 enquanto o ai-dev-core usa v5.4). O Boost MCP do alvo é quem reflete o real — por isso os agentes sempre consultam o Boost do alvo antes de gerar código, em vez de assumir que a versão do ai-dev-core se aplica.

---

## 2. Modelagem do Banco de Dados Relacional (Core), Web UI e API REST

O AI-Dev opera com dois canais de entrada:
- **Web UI (Filament v5):** Gestão humana — cadastrar projetos, configurar agentes, inserir tarefas/PRDs manualmente, monitorar progresso em tempo real via dashboard.
- **API REST:** Permite que sistemas externos (webhooks do GitHub, pipelines de CI/CD, extensões de IDE) injetem tarefas e consultem o progresso programaticamente via Laravel API Resources.

O Orquestrador continua operando em background via *polling/events* nestas tabelas.

### 2.1. Tabelas Principais (Esquema Completo)

**`projects`** — Cadastro de cada sistema/aplicação gerenciado pelo AI-Dev.
Cada projeto é um site/app Laravel distinto (ex: `italoandrade.com`, `meuapp.com.br`).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único do projeto |
| `name` | String(255) | Nome legível do projeto (único) |
| `github_repo` | String(255) / Nullable | URL do repositório GitHub (ex: `italokandrade/portal`) |
| `local_path` | String(500) / Nullable | Caminho absoluto no servidor (ex: `/var/www/html/projetos/portal`) |
| `anthropic_session_id` | String / Nullable | UUID da conversa persistida pelo SDK (trait `RemembersConversations`) — contexto infinito por projeto |
| `prd_payload` | JSON / Nullable | PRD Master do projeto — módulos de alto nível gerados pelo `ProjectPrdAgent` |
| `prd_approved_at` | Timestamp / Nullable | Quando o PRD Master foi aprovado — libera geração do Blueprint Técnico |
| `blueprint_payload` | JSON / Nullable | Blueprint Técnico Global — MER/ERD conceitual sem campos, casos de uso, workflows, arquitetura C4 simplificada, integrações e API surface |
| `blueprint_approved_at` | Timestamp / Nullable | Quando o Blueprint Técnico foi aprovado — libera `createModulesFromPrd()` |
| `status` | Enum: `active`, `paused`, `scaffold_failed`, `archived` | Status operacional. `paused` = aceita tasks mas não processa; `scaffold_failed` = alvo sem scaffold Laravel/AI/MCP completo |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |

---

**`tasks`** — Cada tarefa de desenvolvimento solicitada (via UI, API ou Sentinela).
Uma task é sempre acompanhada de um PRD completo e está sempre vinculada a um módulo/submódulo específico do projeto.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único da tarefa |
| `project_id` | FK → `projects.id` | A qual projeto pertence |
| `module_id` | FK → `project_modules.id` / Nullable | Submódulo ao qual a task pertence (null = task avulsa) |
| `title` | String(500) | Título legível da tarefa (ex: "Criar Resource de Usuários") |
| `prd_payload` | JSON | O PRD completo em formato JSON estruturado (ver `PRD_SCHEMA.md`) |
| `status` | Enum (ver abaixo) | Estado atual na máquina de estados |
| `priority` | Enum: `low`, `normal`, `high`, `critical` | Prioridade de execução |
| `assigned_agent_id` | FK → `agents_config.id` / Nullable | Qual agente está responsável (preenchido pelo Orchestrator) |
| `git_branch` | String(100) / Nullable | Nome do branch Git criado para isolar esta task (ex: `task/a1b2c3d4`) |
| `commit_hash` | String(40) / Nullable | Hash do commit final da task (último commit após todas subtasks aprovadas). Permite rollback completo |
| `last_session_id` | String / Nullable | ID da conversa LLM usada nesta tarefa para manter contexto |
| `retry_count` | Int (default: 0) | Quantas vezes esta task já foi re-executada após falha |
| `max_retries` | Int (default: 3) | Limite de retentativas antes de escalar para Human-in-the-Loop |
| `error_log` | Text / Nullable | Último erro registrado (stack trace, mensagem de falha) |
| `source` | Enum: `manual`, `prd`, `specification`, `webhook`, `sentinel`, `ci_cd` | De onde esta task veio (PRD, spec legada, UI, Sentinela, GitHub webhook etc.) |
| `is_redo` | Boolean (default: false) | Se esta task é uma re-execução (redo) de uma task anterior em vez de uma task nova |
| `original_task_id` | FK → `tasks.id` / Nullable | ID da task original quando é um redo — permite rastrear a cadeia de tentativas |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |
| `started_at` | Timestamp / Nullable | Quando o processamento começou |
| `completed_at` | Timestamp / Nullable | Quando finalizou (sucesso ou falha terminal) |

**Máquina de Estados da Task (Transições Permitidas):**

```text
                    ┌──────────────────────────────────────┐
                    │                                      │
                    ▼                                      │
  ┌─────────┐   ┌──────────────┐   ┌───────────┐   ┌─────┴─────┐
  │ pending  │──▶│ in_progress  │──▶│ qa_audit  │──▶│ testing   │
  └─────────┘   └──────┬───────┘   └─────┬─────┘   └─────┬─────┘
                       │                 │                │
                       │            ┌────▼────┐     ┌────▼──────┐
                       │            │ rejected│     │ completed │
                       │            └────┬────┘     └───────────┘
                       │                 │
                       │    (retry_count < max_retries?)
                       │         │              │
                       │        SIM            NÃO
                       │         │              │
                       │         ▼              ▼
                       │    in_progress    ┌──────────┐
                       │                   │ escalated │ ◀── Human-in-the-Loop
                       │                   └──────────┘
                       │
                       ▼
                  ┌──────────┐
                  │ rollback │ ◀── Falha catastrófica (git revert)
                  └──┬───────┘
                     │
                     ▼
                  ┌────────┐
                  │ failed │ ◀── Falha terminal irrecuperável
                  └────────┘
```

**Cada transição é gravada na tabela `task_transitions` (log de auditoria).** Nenhuma transição inválida é permitida — o Model (Eloquent) valida antes de salvar. Por exemplo, uma task em `pending` não pode pular para `completed` sem passar por `in_progress` e `qa_audit`.

---

**`subtasks`** — A quebra granular feita pelo Orchestrator. Cada subtask é um "pacote de trabalho" para um subagente executor específico.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `task_id` | FK → `tasks.id` | Task pai que gerou esta subtask |
| `title` | String(500) | Título descritivo (ex: "Criar Migration de users com soft delete") |
| `sub_prd_payload` | JSON | Mini-PRD focado apenas na responsabilidade deste subagente |
| `status` | Enum: `pending`, `running`, `qa_audit`, `success`, `error`, `blocked` | Estado atual |
| `assigned_agent` | String(50) | Slug do agente executor (ex: `backend-specialist`, `frontend-specialist`) |
| `dependencies` | JSON / Nullable | Array de UUIDs de subtasks que precisam terminar ANTES desta começar |
| `execution_order` | Int | Ordem de execução dentro do grupo (1, 2, 3...) |
| `result_log` | Text / Nullable | Saída completa da execução (código gerado, comandos rodados) |
| `result_diff` | Text / Nullable | O `git diff` exato produzido por esta subtask |
| `commit_hash` | String(40) / Nullable | Hash do commit Git gerado ao aprovar a subtask. Permite rollback preciso via `git revert <hash>` |
| `files_modified` | JSON / Nullable | Array de caminhos dos arquivos tocados (ex: `["/app/Models/User.php"]`) |
| `file_locks` | JSON / Nullable | Arquivos que esta subtask travou para escrita exclusiva (mutex) |
| `retry_count` | Int (default: 0) | Retentativas consumidas |
| `max_retries` | Int (default: 3) | Limite de retentativas para esta subtask |
| `qa_feedback` | Text / Nullable | Feedback detalhado do QA Auditor em caso de rejeição |
| `created_at` | Timestamp | Data de criação |
| `started_at` | Timestamp / Nullable | Quando o subagente começou a trabalhar |
| `completed_at` | Timestamp / Nullable | Quando terminou |

**Por que `file_locks`?** Quando duas subtasks rodam em paralelo e ambas tentam editar o mesmo arquivo (ex: `routes/web.php`), ocorre uma "race condition" que corrompe o código. O campo `file_locks` funciona como um **mutex por arquivo**: antes de um subagente escrever num arquivo, ele verifica se alguma outra subtask `running` já travou aquele arquivo. Se sim, ele espera (status `blocked`) até a outra terminar. Isso é gerenciado pelo Orchestrator, não pelos subagentes — eles apenas obedecem.

---

**`agents_config`** — Configuração dinâmica de cada agente do ecossistema.
Permite trocar o modelo de IA, ajustar o temperatura e alterar o system prompt de qualquer agente **sem mexer em código** — tudo via Filament UI.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | String / PK | Identificador do agente (ex: `orchestrator`, `qa-auditor`, `backend-specialist`) |
| `display_name` | String(100) | Nome legível para a UI (ex: "Especialista Backend TALL") |
| `role_description` | Text | System Prompt base que define o comportamento do agente |
| `provider` | String(50) | Provedor de IA registrado em `config/ai.php` (ex: `openrouter`, `ollama`) |
| `model` | String(100) | Modelo específico (ex: `anthropic/claude-opus-4.7`, `anthropic/claude-sonnet-4-6`, `anthropic/claude-haiku-4-5-20251001`) |
| `api_key_env_var` | String(100) | Nome da variável de ambiente com a chave API (ex: `OPENROUTER_API_KEY`) |
| `temperature` | Float (0.0 - 2.0) | Criatividade (0.0 = determinístico, 1.0+ = criativo). Orchestrator usa 0.2, Executores usam 0.4 |
| `max_tokens` | Int | Máximo de tokens de saída por resposta. Padrão: 8192 |
| `knowledge_areas` | JSON | Array de áreas de conhecimento do agente (ex: `["backend", "database", "filament"]`) |
| `max_parallel_tasks` | Int (default: 1) | Quantas subtasks este agente pode processar simultaneamente |
| `is_active` | Boolean (default: true) | Se o agente está disponível para receber tarefas |
| `fallback_agent_id` | String / Nullable / FK → `agents_config.id` | Agente substituto se este falhar (redundância) |

**Estratégia de Providers de IA (Decisão de Arquitetura):**

O AI-Dev usa **dois proxies de IA** com papéis invertidos por classe de agente:

| Classe | Provider | Modelo | Motivo |
|---|---|---|---|
| **Orchestrator / Specification / Quotation / Refine** | **openrouter** | `anthropic/claude-opus-4.7` | Máxima qualidade para planejamento e especificação |
| **SpecialistAgent / QAAuditorAgent** | **openrouter** | `anthropic/claude-sonnet-4-6` | Qualidade + custo para execução e auditoria de código |
| **DocsAgent** | **openrouter** | `anthropic/claude-haiku-4-5-20251001` | Rápido e barato para consultas de documentação |
| **Context Compressor** | **Ollama** (local) | `qwen2.5:0.5b` | Sem custo de API; modelo leve suficiente para sumarização (Fase 3) |

**SDK Default (`config/ai.php`):** `openrouter` — provider padrão para todos os agentes e módulos da aplicação. Família Anthropic: Opus 4.7 (planejamento), Sonnet 4.6 (código/QA), Haiku 4.5 (docs).

**Agentes Padrão Pré-Configurados:**

| ID | Papel | Provider | Modelo | Temperatura |
|---|---|---|---|---|
| `orchestrator` | Planner — Recebe o PRD e quebra em Sub-PRDs | `openrouter` | claude-opus-4.7 | 0.2 |
| `qa-auditor` | Judge — Audita cada entrega contra o PRD | `openrouter` | claude-sonnet-4-6 | 0.1 |
| `security-specialist` | Auditor — Pentest, OWASP Top 10, vulnerabilidades | `openrouter` | claude-sonnet-4-6 | 0.1 |
| `performance-analyst` | Analista — N+1 queries, slow queries, otimizações | `openrouter` | claude-sonnet-4-6 | 0.2 |
| `backend-specialist` | Executor — Controllers, Models, Services, Migrations | `openrouter` | claude-sonnet-4-6 | 0.4 |
| `frontend-specialist` | Executor — Blade, Livewire, Alpine.js, Tailwind, Anime.js | `openrouter` | claude-sonnet-4-6 | 0.5 |
| `filament-specialist` | Executor — Resources, Pages, Widgets, Forms, Tables Filament v5 | `openrouter` | claude-sonnet-4-6 | 0.3 |
| `database-specialist` | Executor — Migrations, Seeders, Queries complexas | `openrouter` | claude-sonnet-4-6 | 0.2 |
| `devops-specialist` | Executor — CI/CD, deploy, permissões, Supervisor | `openrouter` | claude-sonnet-4-6 | 0.2 |
| `context-compressor` | Utilitário — Comprime sessões longas em resumos | `ollama` (qwen2.5:0.5b) | — | 0.1 |

---

**`project_specifications`** — Especificação técnica gerada pela IA (legado — mantido para retrocompatibilidade).
O fluxo ativo usa `projects.prd_payload` (PRD Master), `projects.blueprint_payload` (Blueprint Técnico Global) e `project_modules.prd_payload`/`blueprint_payload` (PRD Técnico e contribuição de módulo).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto associado |
| `user_description` | Text | Descrição informal do sistema enviada pelo usuário |
| `ai_specification` | JSON | Especificação estruturada gerada pela IA (módulos, submódulos, features, stack) |
| `version` | Int (default: 1) | Versão da especificação |
| `approved_at` | Timestamp / Nullable | Quando foi aprovada |
| `approved_by` | FK → `users.id` / Nullable | Quem aprovou |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |

**Fluxo ativo (Granularidade Progressiva):**
1. `ProjectPrdAgent` gera `projects.prd_payload` com módulos de alto nível de negócio
2. `StandardProjectModuleService` anexa `standard_modules` (`Chatbox` e `Segurança`) e cria esses módulos como concluídos em `project_modules`
3. `Project::approvePrd()` libera `GenerateProjectBlueprintJob`
4. `ProjectBlueprintAgent` gera `projects.blueprint_payload`
5. `Project::approveBlueprint()` + `createModulesFromPrd()` cria módulos raiz de negócio e vincula dependências ao core padrão
6. `ModulePrdAgent` gera `project_modules.prd_payload` técnico por módulo, usando o Blueprint como trilho
7. `ProjectBlueprintService` incorpora `blueprint_contribution` do módulo ao Blueprint Global
8. `ProjectRepositoryService` exporta `.ai-dev/architecture/domain-model.mmd`, `.md` e `.json` a partir do Blueprint progressivo
9. O PRD do módulo decide `needs_submodules` → submódulos ou tasks; folhas com schema criam primeiro uma task de checkpoint de arquitetura de dados
10. Em módulos folha, `implementation_items` é a fonte preferencial das tasks. `acceptance_criteria` e `qa_scenarios` ficam como validação do PRD da task, evitando transformar todo critério em uma tarefa `Teste:` separada.
11. Se o PRD de módulo vier com estrutura pobre, mas recuperável, a cascata normaliza aliases (`tables` → `database_schema.tables`, `apis` → `api_endpoints`), preenche título/objetivo ausentes, sintetiza `implementation_items` a partir de tabelas/APIs/componentes e cria QA mínimo quando necessário.

**Fluxo em cascata (Auto Aprovação — `CascadeModulePrdJob`):**
1. Acionado pelo botão "Auto Aprovar Blueprint — Cascata Completa" em `ViewProject`
2. Aprova o Blueprint, cria módulos raiz de planejamento e despacha `CascadeModulePrdJob` para cada um, sem instalar o Projeto Alvo
3. Cada job gera o PRD técnico via `ModulePrdAgent`, incorpora a contribuição ao Blueprint, auto-aprova e:
   - Se `needs_submodules: true` → cria submódulos e despacha o job para cada filho
   - Se `needs_submodules: false` → normaliza o PRD de folha e cria tasks (`status: pending`) a partir de `implementation_items` quando presentes; sem esses itens, usa componentes/workflows/APIs como fallback e só expande critérios de aceite em tasks quando não há outra superfície implementável
4. O ciclo repete recursivamente até todas as folhas terem tasks
5. Resiliência: pula geração se PRD válido já existe; não duplica submódulos/tasks; `failed()` preserva PRD válido já salvo

**Guardrails de planejamento:** a cascata não usa mais tetos pequenos hardcoded. Os limites operacionais vivem em `config/ai_dev.php` e podem ser ajustados por ambiente (`AI_DEV_MAX_ROOT_MODULES_PER_PROJECT`, `AI_DEV_MAX_MODULES_PER_PROJECT`, `AI_DEV_MAX_SUBMODULE_DEPTH`, `AI_DEV_MAX_SUBMODULES_PER_MODULE`, `AI_DEV_MAX_TASKS_PER_MODULE`). O padrão foi calibrado para sistemas grandes: 200 módulos raiz, 1000 módulos totais, 3 níveis de submódulos, 30 submódulos por módulo e 30 tasks por folha. Valor `0` remove o teto específico.

**Estrutura do `ai_specification` JSON (legado):**
```json
{
  "system_name": "Portal ItaloAndrade",
  "objective": "...",
  "target_audience": "...",
  "core_features": ["..."],
  "technical_stack": {"backend": "Laravel 13", "frontend": "Livewire 4 + Alpine.js v3", "admin": "Filament v5", "database": "PostgreSQL 16"},
  "non_functional_requirements": ["..."],
  "modules": [
    {
      "name": "Autenticação",
      "description": "...",
      "priority": "high",
      "submodules": [
        {"name": "Login/Logout", "description": "...", "priority": "high"},
        {"name": "Recuperação de senha", "description": "...", "priority": "normal"}
      ]
    }
  ],
  "estimated_modules": 5,
  "estimated_complexity": "medium"
}
```

**Ao aprovar (fluxo legado):** `ProjectSpecification::approve($user)` chama `createModulesAndSubmodules()` que percorre o JSON e cria módulos e submódulos.

**Fluxo ativo:** `Project::approvePrd()` chama `createModulesFromPrd()` que cria **apenas módulos raiz** (`parent_id = null`). Submódulos são criados posteriormente via `GenerateModuleSubmodulesJob` quando o PRD técnico do módulo define `needs_submodules = true`.

---

**`project_modules`** — Módulos e submódulos do projeto (estrutura hierárquica).
O sistema adota **granularidade progressiva**: cada módulo/submódulo possui seu próprio PRD técnico (`prd_payload`) e uma contribuição de Blueprint (`blueprint_payload`) que decide o próximo passo e enriquece o desenho global.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto associado |
| `parent_id` | FK → `project_modules.id` / Nullable | Módulo pai (null = módulo raiz; preenchido = submódulo) |
| `name` | String(255) | Nome do módulo/submódulo |
| `description` | Text | Descrição do que este módulo abrange |
| `status` | Enum: `planned`, `in_progress`, `testing`, `completed`, `revision` | Estado atual |
| `priority` | Enum: `low`, `normal`, `high`, `critical` | Prioridade de desenvolvimento |
| `dependencies` | JSON / Nullable | Array de UUIDs de módulos que devem ser concluídos antes deste |
| `progress_percentage` | TinyInt (0-100) | % calculado automaticamente com base nas tasks concluídas |
| `prd_payload` | JSON / Nullable | PRD Técnico do módulo — gerado pelo `ModulePrdAgent`. Contém: objetivo, schema, APIs, regras, workflows, critérios, `needs_submodules`, `submodules` |
| `blueprint_payload` | JSON / Nullable | Contribuição deste módulo ao Blueprint Global: entidades, campos, relacionamentos, workflows, componentes, integrações e APIs |
| `started_at` | Timestamp / Nullable | Quando o desenvolvimento começou |
| `completed_at` | Timestamp / Nullable | Quando foi concluído |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |

**Hierarquia e lógica (Granularidade Progressiva):**
- `parent_id = null` → módulo raiz (agrupador de alto nível, ex: "Autenticação", "Dashboard")
- `parent_id = uuid` → submódulo (criado apenas quando o PRD do pai define `needs_submodules = true`)
- O PRD de cada módulo (`prd_payload`) decide se precisa de submódulos (`needs_submodules` boolean)
- Tasks são criadas **apenas em nós folha** (módulos/submódulos sem filhos e com `needs_submodules = false`)
- Submódulos podem ter seus próprios submódulos; a profundidade automática é governada por `config/ai_dev.php` para evitar loop de planejamento
- `recalculateProgress()` conta tasks completadas / total tasks do módulo folha
- `dependenciesMet()` verifica se todos os módulos dependência estão com status `completed`

---

**`project_features`** — Funcionalidades geradas por IA para cada projeto, separadas por camada.
Geradas automaticamente pelo `GenerateFeaturesAgent` (via `GenerateProjectFeaturesJob`) a partir do PRD Master e da descrição do projeto. Cada feature pode ser refinada individualmente pelo `RefineFeatureAgent` diretamente no painel Filament. Servem como contexto adicional durante o fluxo de especificação.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto associado |
| `type` | Enum: `backend`, `frontend` | Camada da funcionalidade |
| `title` | String(255) | Título curto da funcionalidade |
| `description` | Text | O que a funcionalidade faz e qual valor entrega |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |

**Fluxo de geração:** o usuário, dentro do `ProjectResource` no Filament, dispara via botão de ação a geração de features para a camada desejada (`backend` ou `frontend`). O job usa o `GenerateFeaturesAgent` com o nível Premium de IA, parseia o JSON retornado e insere os registros. Features individuais podem ser refinadas com o `RefineFeatureAgent`.

---

**`project_quotations`** — Orçamentos e análise de ROI por projeto.
Compara o custo de desenvolvimento humano vs. AI-Dev para demonstrar o valor da automação.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` / Nullable | Projeto associado (opcional) |
| `client_name` | String(255) | Nome do cliente |
| `project_name` | String(255) | Nome do projeto cotado |
| `project_description` | Text | Descrição breve do escopo |
| `complexity_level` | TinyInt (1-4) | 1=Simples, 2=Médio, 3=Complexo, 4=Enterprise |
| `required_areas` | JSON | Checklist de áreas: backend, frontend, mobile, database, devops, design, testing, security, pm |
| `*_hours` | Int | Horas estimadas por área (backend_hours, frontend_hours, etc.) |
| `urgency_level` | TinyInt (1-4) | 1=Normal, 2=Moderado, 3=Urgente, 4=Crítico |
| `delivery_days` | Int | Prazo em dias |
| `hourly_rate_*` | Decimal(8,2) | Taxa horária em BRL por área (defaults: backend R$120, frontend R$110, etc.) |
| `urgency_multiplier` | Decimal(4,2) | Multiplicador de urgência (1.00 a 2.00) |
| `complexity_multiplier` | Decimal(4,2) | Multiplicador de complexidade (0.80 a 1.80) |
| `team_size` | Int | Tamanho de equipe calculado pela urgência |
| `total_human_hours` | Decimal(10,2) | Total de horas (sem multiplicadores) |
| `total_human_cost` | Decimal(12,2) | Custo humano total em BRL |
| `ai_dev_cost` | Decimal(12,2) | Custo real do AI-Dev (tokens USD×5.80 + infra) |
| `ai_dev_price` | Decimal(12,2) | Preço sugerido do AI-Dev (15% do custo humano, mínimo R$500) |
| `savings_amount` | Decimal(12,2) | Economia em BRL |
| `savings_percentage` | Decimal(12,2) | % de economia |
| `actual_token_cost_usd` | Decimal(10,6) | Custo real de tokens (pós-execução) |
| `actual_infra_cost` | Decimal(10,2) | Custo real de infra (pós-execução) |
| `status` | Enum: `draft`, `sent`, `approved`, `rejected`, `in_progress`, `completed` | Estado do orçamento |
| `notes` | Text / Nullable | Observações internas |
| `sent_at` | Timestamp / Nullable | Quando foi enviado ao cliente |
| `approved_at` | Timestamp / Nullable | Quando foi aprovado |
| `created_at` | Timestamp | Data de criação |

**Método `recalculate()`:** Recalcula automaticamente todos os campos derivados (total_human_hours, total_human_cost, ai_dev_price, savings_amount, savings_percentage) baseado nas horas e taxas preenchidas.

---

**`context_library`** *(Fase 3 — não implementado ainda)* — Padrões estritos de código (a "Bíblia TALL").
Cada registro é um exemplo de código perfeito que os agentes DEVEM seguir ao gerar código. Funciona como um "few-shot" fixo que não depende do RAG vetorial.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `category` | Enum (ver abaixo) | Categoria do padrão TALL |
| `title` | String(255) | Nome descritivo (ex: "Resource Filament v5 com Tabs") |
| `content` | Text | Código de exemplo perfeito que o agente deve replicar |
| `description` | Text | Quando e por que usar este padrão. Regras e restrições |
| `stack_component` | Enum: `tailwind`, `alpine`, `laravel`, `livewire`, `filament`, `animejs` | Qual componente TALL |
| `knowledge_area` | String(50) | Área de conhecimento (ex: `backend`, `frontend`, `database`) |
| `is_active` | Boolean (default: true) | Se este padrão está ativo (pode desativar padrões obsoletos) |
| `version` | String(20) | Versão do framework ao qual o padrão se refere (ex: "filament-v5") |

**Categorias Disponíveis:**
- `filament_resource` — Resources completos (CRUD) no Filament v5
- `filament_widget` — Widgets de dashboard
- `filament_form` — FormBuilder patterns
- `filament_table` — TableBuilder patterns
- `filament_action` — Actions (Bulk, Header, Row)
- `livewire_component` — Componentes Livewire com Alpine.js
- `blade_layout` — Layouts e partials Blade
- `animejs_animation` — Animações com Anime.js integradas ao Alpine
- `eloquent_model` — Models com relationships, scopes, casts
- `laravel_service` — Services, Actions, DTOs
- `laravel_migration` — Migrations com best practices
- `laravel_test` — Testes Pest/PHPUnit
- `tailwind_pattern` — Padrões de design com Tailwind CSS

---

### 2.2. Core Padrao dos Projetos Alvo

De acordo com o `STANDARD_MODULES.md`, todo Projeto Alvo nasce no planejamento com `Chatbox` e `Segurança`. O `StandardProjectModuleService` registra esses módulos no banco do ai-dev-core como concluídos. O `/var/www/html/projetos/ai-dev/instalar_projeto.sh` copia os arquivos base do `ai-dev-core` para o projeto criado e roda as migrations no banco do alvo somente depois que o orçamento é aprovado.

O mesmo scaffold provisiona o runtime individual do alvo para os agentes: Laravel AI SDK, Laravel MCP, Laravel Boost, `config/ai.php`, `config/mcp.php`, migrations do SDK e `.mcp.json` apontando para `php artisan boost:mcp`. O `BoostTool` sempre executa `boost:execute-tool` dentro de `projects.local_path`, portanto cada projeto fornece seu proprio MCP e contexto, assim como fornece seu proprio `github_repo`.

Antes do orçamento aprovado, o ai-dev-core não exige scaffold físico para aprovar Blueprint, criar módulos, iniciar cascata ou gerar tasks. O repositório do alvo recebe apenas `.ai-dev/` e o checkpoint de arquitetura usa SQLite temporário. Depois da aprovação do orçamento, o `ScaffoldProjectJob` valida se o alvo possui `artisan`, `composer.json`, `.mcp.json`, `config/ai.php` e `config/mcp.php`; falhas marcam o projeto como `scaffold_failed` e impedem execução de implementação até o scaffold ser corrigido.

Quando `projects.github_repo` está preenchido, o `ProjectRepositoryService` prepara o Git do Projeto Alvo: inicializa `.git` se necessário, configura `origin` com o repositório cadastrado, define identidade local do agente e mantém `.ai-dev/` sincronizado na raiz do repositório daquele alvo (`PROJECT.md`, PRD Master, Blueprint Técnico, módulos, tasks e subtasks). Essa sincronização faz commit e push no repositório do alvo; commits de implementação aprovados pelo `QAAuditJob` também são enviados ao mesmo `origin`. Os comandos Git são executados com `safe.directory` específico do alvo para permitir repositórios com ownership diferente do processo do ai-dev-core.

### 1.B. Checkpoint Físico de Arquitetura de Dados

O MER não fica apenas virtual. O Blueprint progressivo continua sendo a intenção do domínio, mas agora também carrega cobertura por módulo, lifecycle de dados/conteúdo, estados conceituais e riscos. O sistema exporta artefatos oficiais para cada Projeto Alvo em `.ai-dev/architecture/`:

- `domain-model.mmd` — ERD em Mermaid, versionável e legível por IA.
- `domain-model.md` — Markdown com o diagrama Mermaid e tabelas de entidades/relacionamentos.
- `domain-model.json` — payload normalizado do domínio.
- `checkpoint-protocol.md` — rotina obrigatória antes de interfaces, APIs ou fluxos.

Quando um módulo folha possui `database_schema.tables` ou `blueprint_contribution.domain_model`, o `ModuleTaskPlannerService` cria uma task `architecture` antes das tasks de componente/API/teste. Essa task deve criar ou ajustar migrations, Models e relacionamentos Eloquent, validar as migrations em SQLite temporário (`database/ai_dev_architecture.sqlite`) e gerar/conferir ERD físico quando `beyondcode/laravel-er-diagram-generator` estiver instalado. A validação em Postgres de desenvolvimento/staging ocorre somente depois da aprovação do orçamento e do scaffold físico. Tarefas de Filament, Livewire, Controllers, APIs ou Views recebem no PRD a obrigação de confirmar esse checkpoint antes de implementar.

No PRD, esses blocos ficam em `projects.prd_payload.standard_modules`; `projects.prd_payload.modules` continua reservado para módulos de negócio gerados pela IA.

**`activity_log`** — Log de Ações Globais (Spatie Activitylog).
Tabela estritamente de leitura no painel que rastreia ações CRUD e eventos relevantes do sistema. A cobertura vem de duas camadas: Models críticos usam `LogsActivity`; `ActivityAuditService` atua como fallback automático para novos Models em `App\Models`, Models Spatie de roles/permissões e eventos de atribuição/remoção de roles/permissões.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | BigInt / PK | Identificador único do log |
| `log_name` | String / Nullable | Canal lógico do log |
| `description` | Text | Descrição amigável do evento |
| `subject_type` / `subject_id` | Morph / Nullable | Model afetado |
| `causer_type` / `causer_id` | Morph / Nullable | Usuário/ator causador |
| `event` | String / Nullable | Evento (`created`, `updated`, `deleted`, `role_attached`, etc.) |
| `properties` | JSON / Nullable | Estado anterior/novo ou metadados do evento |
| `batch_uuid` | UUID / Nullable | Agrupamento opcional de logs |
| `created_at` | Timestamp | Quando ocorreu |

**`roles`, `permissions`, `model_has_roles`, `role_has_permissions`** — Controle de Acesso (ACL) e Perfis de Usuários.
Gerenciados pelo Spatie Permission + Filament Shield. Cada perfil recebe permissões granulares por Resource, Page e Widget do painel.

| Tabela | Função principal |
|---|---|
| `roles` | Perfis de acesso com `name` e `guard_name` |
| `permissions` | Permissões geradas pelo Shield (`View:DashboardChat`, `Create:Project`, etc.) |
| `model_has_roles` | Atribuição de perfis a usuários |
| `role_has_permissions` | Permissões vinculadas a cada perfil |
| `users` | Tabela padrão do Laravel; acesso ao painel exige ao menos um role Spatie |

**`FilamentShieldPermissionSyncService`** — Sincronização automática de permissões.
Em cada boot seguro da aplicação, lê Resources, Pages, Widgets e permissões customizadas descobertas pelo Shield. Permissões novas são criadas em `permissions` e concedidas automaticamente somente ao perfil `super_admin`; os demais perfis continuam sem a permissão até configuração manual. As policies do core usam o mesmo padrão de nomes do Shield (`ViewAny:Model`, `View:Model`, `Create:Model`, `Update:Model`, `Delete:Model`), evitando permissões visíveis no cadastro que não tenham efeito real.

**`SystemSurfaceMapService`** — Mapa Vivo do Sistema.
Descobre automaticamente Models, Resources, Pages, Widgets e rotas administrativas. Esse mapa alimenta filtros de auditoria e permite que novas superfícies, como um gráfico criado como Widget Filament, entrem no inventário do sistema sem hardcode manual. Classes equivalentes são agrupadas por aliases (`security.roles`, `security.permissions`) para que filtros como "Módulo" não exibam rótulos repetidos.

**`system_settings`** — Configurações do Sistema e Configurações de IA.
Evita hardcoding no `.env` para credenciais das **IAs de interação** e chaves gerais de APIs.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | PK | Identificador único |
| `key` | String(100) | Chave de configuração (ex: `ai_interaction_provider`, `ai_interaction_key`, `ai_interaction_model`) |
| `value` | Text / JSON | Valor associado, podendo ser um JSON para objetos complexos |
| `is_encrypted`| Boolean | Se o valor (como a API Key) está salvo criptografado no banco |
| `group` | String(50) | Grupo (ex: `ai_settings`, `mail`, `identity`) |

> **Nota sobre a IA Operária vs IA de Interação:** O ai-dev-core utiliza o `openrouter` configurado no `.env` do servidor (coluna `api_key_env_var` em `agents_config`) **apenas** para seus agentes de desenvolvimento (`Orchestrator`, `Specialist`, etc.). Já a IA usada pelas integrações internas do painel administrativo (os "assistants" ou "copilotos" do projeto e de cada projeto alvo) lê suas credenciais e modelos a partir da tabela `system_settings` gerenciada via UI.

---

**`task_transitions`** — Log de auditoria de toda mudança de estado.
Cada vez que uma task ou subtask muda de estado (ex: `pending` → `in_progress`), um registro é gravado aqui. Isso permite reconstruir a linha do tempo completa de qualquer tarefa e diagnosticar gargalos.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `entity_type` | Enum: `task`, `subtask` | Se é uma transição de task ou subtask |
| `entity_id` | UUID | ID da task ou subtask |
| `from_status` | String(30) | Estado anterior (null se for a criação) |
| `to_status` | String(30) | Novo estado |
| `triggered_by` | String(50) | Quem causou a transição (ex: `orchestrator`, `qa-auditor`, `sentinel`, `user`) |
| `metadata` | JSON / Nullable | Dados extras (ex: motivo da rejeição, número da retentativa) |
| `created_at` | Timestamp | Quando a transição ocorreu |

---

**`agent_executions`** *(Fase 2 — não implementado ainda)* — Log detalhado de cada chamada LLM feita por qualquer agente.
Essencial para controle de custo, debugging e otimização.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `agent_id` | FK → `agents_config.id` | Qual agente fez a chamada |
| `subtask_id` | FK → `subtasks.id` / Nullable | Subtask associada (se aplicável) |
| `task_id` | FK → `tasks.id` / Nullable | Task associada |
| `provider` | String(50) | Provedor usado nesta chamada (ex: `openrouter`, `ollama`) |
| `model` | String(100) | Modelo efetivamente usado na chamada (ex: `anthropic/claude-sonnet-4-6`) |
| `prompt_tokens` | Int | Tokens de entrada consumidos |
| `completion_tokens` | Int | Tokens de saída gerados |
| `total_tokens` | Int | Total de tokens (entrada + saída) |
| `estimated_cost_usd` | Decimal(10,6) | Custo estimado em USD (calculado com base na tabela de preços do provedor) |
| `latency_ms` | Int | Tempo de resposta em milissegundos |
| `status` | Enum: `success`, `error`, `timeout`, `rate_limited` | Resultado da chamada |
| `error_message` | Text / Nullable | Mensagem de erro se a chamada falhou |
| `session_id` | String / Nullable | ID da sessão/conversa usada (para contexto persistente) |
| `cached` | Boolean (default: false) | Se a chamada usou prompt caching (economia de tokens) |
| `created_at` | Timestamp | Quando a chamada foi feita |

**Por que logamos cada chamada LLM?** Sem essa tabela, é impossível saber: quanto estamos gastando por projeto/tarefa, qual agente consome mais tokens, se o prompt caching está funcionando, e se algum agente está fazendo chamadas excessivas (loop). O dashboard Filament lê esta tabela para mostrar métricas em tempo real.

---

**`tool_calls_log`** *(Fase 2 — migration existe; listener `Tool::dispatched()` pendente)* — Registro de cada ferramenta executada pelos agentes.
A camada de segurança e auditoria — permite investigar exatamente quais comandos foram rodados, quais arquivos foram alterados, e por quem.

**Listener de auditoria (`Tool::dispatched()`):** O Laravel AI SDK expõe o evento `Tool::dispatched()` que deve ser usado para popular esta tabela de forma transparente, sem poluir o `handle()` de cada tool:

```php
// AppServiceProvider::boot()
Tool::dispatched(function (Tool $tool, Request $request, string $result) {
    ToolCallsLog::create([
        'tool_name'        => class_basename($tool),
        'input_params'     => $request->all(),
        'output_result'    => $result,
        'execution_time_ms'=> ...,
        'security_flag'    => false,
    ]);
});
```

Esta é a abordagem recomendada pelo blog "Production-Safe Database Tools for Agents" para rastreabilidade de produção.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `agent_execution_id` | FK → `agent_executions.id` | A qual chamada LLM esta tool call pertence |
| `subtask_id` | FK → `subtasks.id` / Nullable | Subtask associada |
| `tool_name` | String(50) | Nome da ferramenta (ex: `ShellExecuteTool`, `FileWriteTool`) |
| `tool_action` | String(50) | Ação específica (ex: `execute`, `write`, `read`, `search`) |
| `input_params` | JSON | Parâmetros de entrada enviados pelo agente |
| `output_result` | Text / Nullable | Resultado retornado pela ferramenta |
| `status` | Enum: `success`, `error`, `blocked`, `timeout` | Resultado da execução |
| `execution_time_ms` | Int | Tempo de execução em milissegundos |
| `security_flag` | Boolean (default: false) | Se o filtro de segurança detectou algo suspeito |
| `created_at` | Timestamp | Quando foi executada |

---

**`problems_solutions`** *(Fase 3 — não implementado ainda)* — Base de conhecimento auto-alimentada (RAG vetorial).
Toda vez que o Sentinela detecta um erro e os agentes resolvem, a dupla (problema + solução) é gravada aqui automaticamente. Na próxima vez que um erro similar surgir, o RAG vetorial injeta essa solução como few-shot no prompt.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto onde o problema ocorreu |
| `knowledge_area` | String(50) | Área de conhecimento (ex: `backend`, `frontend`, `database`, `filament`) |
| `problem_description` | Text | Descrição do problema / stack trace original |
| `solution_description` | Text | O que foi feito para resolver (resumo) |
| `solution_diff` | Text | O diff exato do código que resolveu o problema |
| `related_files` | JSON | Array de arquivos envolvidos (ex: `["/app/Models/User.php"]`) |
| `tags` | JSON | Tags para busca (ex: `["eloquent", "relationship", "n+1"]`) |
| `embedding` | vector(1536) / Nullable | Vetor de embedding para busca semântica via pgvector nativo no PostgreSQL |
| `confidence_score` | Float (0.0 - 1.0) | O quão confiante estamos que esta solução é correta (baseado em se os testes passaram) |
| `times_reused` | Int (default: 0) | Quantas vezes esta solução já foi reutilizada com sucesso |
| `created_at` | Timestamp | Data de criação |

**Como a auto-alimentação funciona na prática:**
1. O Sentinela detecta um `QueryException` no projeto "Portal ItaloAndrade"
2. Ele cria uma Task de prioridade máxima com o stack trace
3. Os agentes resolvem o problema (ex: faltava um index na migration)
4. Quando a Task vai para `completed`, um **Listener Laravel** (`TaskCompletedListener`) automaticamente:
   - Extrai o PRD do problema e o diff da solução
   - Gera o embedding via modelo local (Ollama) ou API
   - Insere na tabela `problems_solutions` com `knowledge_area = "database"`
5. Na próxima vez que um `QueryException` similar surgir, o passo 3 do fluxo (RAG) encontra esta solução e injeta no prompt do agente, que resolve instantaneamente

---

**`agent_conversations` + `agent_conversation_messages`** — Conversas persistidas automaticamente pelo Laravel AI SDK.

O Laravel 13 AI SDK gerencia automaticamente estas tabelas via o trait `RemembersConversations`. Substituem a antiga tabela `session_history` com compressão manual.

| Tabela | Gerenciada por | Descrição |
|---|---|---|
| `agent_conversations` | SDK (`RemembersConversations`) | Registro de cada conversa por agente/usuário |
| `agent_conversation_messages` | SDK (`RemembersConversations`) | Mensagens individuais (role + content) de cada conversa |

**Uso no código:**
```php
// Iniciar conversa
$response = BackendSpecialist::make()->forUser($user)->prompt('Crie o Model Post');
$conversationId = $response->conversationId;

// Continuar conversa (contexto automático)
$response = BackendSpecialist::make()->continue($conversationId, as: $user)->prompt('Agora adicione soft deletes');
```

**Compressão opcional:** Para sessões longas, o `ContextCompressionJob` pode comprimir o histórico via Ollama (modelo local) e reiniciar a conversa com o resumo comprimido como instrução adicional.

---

**`social_accounts`** *(Fase 1 — CRUD implementado; `SocialPostingAgent` e integração de postagem pendentes)* — Credenciais de redes sociais vinculadas a cada projeto.
O `SocialAccountResource` no Filament está implementado e permite cadastro, edição e visualização de contas. As credenciais ficam armazenadas criptografadas. **A integração de postagem automática** (`SocialPostingAgent` + `hamzahassanm/laravel-social-auto-post`) ainda não foi implementada — quando a feature for completa, será um Agent dedicado chamando o pacote diretamente, não um Tool SDK genérico.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto associado |
| `platform` | Enum: `facebook`, `instagram`, `twitter`, `linkedin`, `tiktok`, `youtube`, `pinterest`, `telegram` | Plataforma |
| `account_name` | String(100) | Nome legível da conta (ex: "Fan Page ItaloAndrade") |
| `credentials` | JSON (criptografado) | Tokens e chaves API da plataforma (ex: `{access_token, page_id}`) |
| `is_active` | Boolean | Se esta conta está habilitada para publicação |
| `last_posted_at` | Timestamp / Nullable | Última publicação realizada |
| `created_at` | Timestamp | Data de criação |

---

**`webhooks_config`** *(Fase 2 — não implementado ainda)* — Configuração de webhooks de entrada para integração com GitHub, CI/CD, etc.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `project_id` | FK → `projects.id` | Projeto associado |
| `source` | Enum: `github`, `gitlab`, `ci_cd`, `custom` | De onde vem o webhook |
| `event_type` | String(100) | Tipo de evento que dispara a ação (ex: `push`, `pull_request`, `issue`, `pipeline_failed`) |
| `secret_token` | String(255) | Token secreto para validar autenticidade do webhook |
| `action` | Enum: `create_task`, `update_status`, `notify` | O que fazer quando o webhook chega |
| `prd_template_id` | UUID / Nullable | Template de PRD a usar se `action = create_task` |
| `is_active` | Boolean (default: true) | Se este webhook está ativo |
| `last_triggered_at` | Timestamp / Nullable | Última vez que foi ativado |
| `created_at` | Timestamp | Data de criação |

---

### 2.2. Relacionamento Visual entre Tabelas (ERD Simplificado)

```text
projects ──┬── 1:N ── project_modules ──┬── 1:N ── project_modules (submódulos, parent_id)
            │   (PRD Master → módulos)   │   └── 1:N ── tasks (apenas em folhas)
            │                            └── 1:N ── tasks (apenas em folhas)
            │
            ├── 1:N ── tasks ──┬── 1:N ── subtasks ──── N:1 ── agents_config
            │   (tasks avulsas) ├── 1:N ── task_transitions
            │                  └── N:1 ── project_modules (module_id)
            │
            ├── 1:N ── project_features ✅ (Fase 1 — implementado, type: backend|frontend)
            ├── 1:N ── project_quotations (orçamentos e ROI)
            ├── 1:N ── project_specifications (legado — retrocompatibilidade)
            ├── 1:N ── agent_conversations (SDK — RemembersConversations)
            ├── 1:N ── social_accounts ✅ (Fase 1 — CRUD Filament implementado; postagem pendente)
            ├── 1:N ── agent_executions 🔜 (Fase 2 — pendente)
            │          └── 1:N ── tool_calls_log ⚠️ (Fase 2 — migration existe, listener pendente)
            ├── 1:N ── problems_solutions 🔜 (Fase 3 — RAG vetorial)
            └── 1:N ── webhooks_config 🔜 (Fase 2 — pendente)

context_library 🔜 (Fase 3 — standalone, padrões globais de código TALL)
```

---

## 3. Protocolo de Comunicação Inter-Agentes (Como Eles "Conversam")

Este é o detalhe técnico mais crítico do sistema: **como exatamente diferentes agentes se comunicam entre si, trocam resultados e se coordenam?**

### 3.1. Modelo Técnico: Agent Classes + Laravel Queue + Redis + Events

A comunicação **NÃO** é feita por chamada HTTP entre serviços, nem por invocação direta de classe. Cada agente é implementado como uma **Agent class** do Laravel AI SDK (`implements Agent`) que pode ser despachada via **Laravel Queue + Redis**, gerenciado pelo **Laravel Horizon + Supervisor**.

```text
                    ┌────────────────────────────┐
                    │  GATILHO (Webhook/UI/Cron)  │
                    └─────────────┬──────────────┘
                                  │
                                  ▼
                    ┌────────────────────────────┐
                    │ OrchestratorJob             │
                    │ Queue: "orchestrator"        │
                    │                              │
                    │ 1. Lê task pending do DB    │
                    │ 2. Chama LLM (Claude)        │
                    │ 3. Quebra PRD em Sub-PRDs    │
                    │ 4. Cria subtasks no DB       │
                    │ 5. Despacha ProcessSubtaskJob │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │  Redis Queue: "executors"     │
                    │                               │
                    │ ┌──────────────────────────┐  │
                    │ │ ProcessSubtaskJob(id=1)   │  │ ◀── Backend Specialist
                    │ │ ProcessSubtaskJob(id=2)   │  │ ◀── Frontend Specialist
                    │ │ ProcessSubtaskJob(id=3)   │  │ ◀── Filament Specialist
                    │ └──────────────────────────┘  │
                    └──────────────┬────────────────┘
                                   │
                        (Cada Job, ao terminar,
                         atualiza subtasks.status
                         e dispara um Event)
                                   │
                                   ▼
                    ┌────────────────────────────────┐
                    │  Event: SubtaskCompletedEvent   │
                    │  (via Redis Pub/Sub broadcast)  │
                    └──────────────┬─────────────────┘
                                   │
                                   ▼
                    ┌────────────────────────────────┐
                    │  QAJobDispatcherListener        │
                    │                                 │
                    │  "Todas as subtasks desta task  │
                    │   terminaram?"                   │
                    │                                 │
                    │  SIM → Despacha QAAuditJob      │
                    │  NÃO → Espera próximo evento    │
                    └──────────────┬──────────────────┘
                                   │
                                   ▼
                    ┌────────────────────────────────┐
                    │  QAAuditJob                     │
                    │  Queue: "qa-auditor"             │
                    │                                 │
                    │  1. Lê PRD original + resultado │
                    │  2. Chama LLM (Claude)          │
                    │  3. Aprovado? → completed       │
                    │  4. Rejeitado? → retry/escalate │
                    └────────────────────────────────┘
```

### 3.2. Filas Redis por Agente (Isolamento e Controle)

Cada "classe" de agente tem sua própria fila Redis, permitindo escalar, pausar ou priorizar agentes individualmente:

| Fila Redis | Agente | Workers | Descrição |
|---|---|---|---|
| `orchestrator` | PRD + Cascata + Orchestrator | N (ajustável) | Compartilhada por todos os jobs de geração de PRD e cascata. Pode-se subir múltiplos workers para paralelizar geração de módulos. |
| `queue:executors` | Subagentes | 3 | Até 3 subagentes executando em paralelo (configurável via Horizon) |
| `queue:qa-auditor` | QA Auditor | 1 | Apenas 1 worker — a auditoria é sequencial |
| `queue:security` | Security Specialist | 1 | Apenas 1 worker — auditoria de segurança pós-QA |
| `queue:performance` | Performance Analyst | 1 | Apenas 1 worker — análise de performance pós-QA |
| `queue:compressor` | Context Compressor | 1 | Apenas 1 worker — compressão de contexto em background |
| `queue:sentinel` | Sentinel Watcher | 1 | Apenas 1 worker — processa erros runtime |

**Workers da fila `orchestrator`:** durante cascata de PRD, múltiplos workers em paralelo aceleram a geração (cada módulo leva 3-7 minutos de chamada LLM). O `CascadeModulePrdJob` é seguro para múltiplos workers porque cada job é vinculado a um módulo específico via `module_id`. Com `tries=3` e `timeout=660s`, jobs individuais podem ser retentados sem duplicar dados (checagem de existência antes de criar submódulos/tasks).

**Como escalar os subagentes?** Basta alterar o `maxProcesses` no config do Horizon para a fila `subtasks`. Em um servidor com mais RAM, pode-se subir para 5 ou 10 workers paralelos. O sistema se adapta automaticamente porque cada `ProcessSubtaskJob` já sabe qual subtask processar (via `subtask_id`).

### 3.3. Classes Laravel Envolvidas (Mapa do Código — Laravel 13)

```text
app/
├── Ai/
│   ├── Agents/
│   │   ├── ProjectPrdAgent.php          ← implements Agent — Gera PRD Master do projeto (apenas módulos)
│   │   ├── ProjectBlueprintAgent.php    ← implements Agent — Gera Blueprint Técnico Global antes dos módulos
│   │   ├── ModulePrdAgent.php           ← implements Agent — Gera PRD Técnico de um módulo (decide submódulos e contribui com o Blueprint)
│   │   ├── GenerateFeaturesAgent.php    ← implements Agent — Gera lista de features por camada (backend|frontend) em JSON
│   │   ├── RefineFeatureAgent.php       ← implements Agent, HasTools, MaxSteps(10) — Refina feature individual via BoostTool
│   │   ├── OrchestratorAgent.php        ← implements Agent, HasStructuredOutput, HasTools — Planner
│   │   ├── QAAuditorAgent.php           ← implements Agent, HasStructuredOutput — Judge
│   │   ├── SpecialistAgent.php          ← implements Agent, Conversational, HasTools — Executor genérico
│   │   ├── SecuritySpecialist.php       ← implements Agent, HasStructuredOutput, HasTools — Auditor de Segurança
│   │   ├── PerformanceAnalyst.php       ← implements Agent, HasStructuredOutput — Analista
│   │   ├── RefineDescriptionAgent.php   ← implements Agent — Refina descrição do projeto com IA
│   │   ├── SpecificationAgent.php       ← implements Agent — Gera especificação técnica (legado)
│   │   ├── QuotationAgent.php           ← implements Agent — Gera orçamento
│   │   ├── DocsAgent.php                ← implements Agent, HasTools — Busca documentação
│   │   ├── SystemAssistantAgent.php     ← implements Agent, HasTools, MaxSteps(10) — Assistente do painel (chat com DB + FileRead)
│   │   └── ContextCompressor.php        ← implements Agent (usa Ollama) — Compressão
│   └── Tools/
│       ├── BoostTool.php                ← implements Laravel\Ai\Contracts\Tool — Boost MCP do Projeto Alvo
│       ├── DocSearchTool.php            ← implements Laravel\Ai\Contracts\Tool — busca docs TALL via Boost
│       ├── FileReadTool.php             ← implements Laravel\Ai\Contracts\Tool — leitura com limites
│       ├── FileWriteTool.php            ← implements Laravel\Ai\Contracts\Tool — escrita/patch validados
│       ├── GitOperationTool.php         ← implements Laravel\Ai\Contracts\Tool — status/diff/commit
│       └── ShellExecuteTool.php         ← implements Laravel\Ai\Contracts\Tool — artisan/composer/npm
│
├── Jobs/
│   ├── GenerateProjectPrdJob.php        ← Gera PRD Master via ProjectPrdAgent → salva em projects.prd_payload
│   ├── GenerateProjectBlueprintJob.php  ← Gera Blueprint Técnico via ProjectBlueprintAgent → salva em projects.blueprint_payload
│   ├── GenerateModulePrdJob.php         ← Gera PRD Técnico via ModulePrdAgent → salva PRD e atualiza Blueprint
│   ├── CascadeModulePrdJob.php          ← Gera PRD + atualiza Blueprint + auto-aprova + despacha filhos recursivamente
│   ├── GenerateModuleSubmodulesJob.php  ← Cria submódulos a partir do PRD técnico do módulo (aprovação manual)
│   ├── GenerateModuleTasksJob.php       ← Cria tasks a partir do PRD técnico de um módulo folha (aprovação manual)
│   ├── GenerateProjectFeaturesJob.php   ← Gera project_features via GenerateFeaturesAgent (type: backend|frontend)
│   ├── SyncProjectRepositoryJob.php     ← Exporta artefatos/PRDs para .ai-dev no repo do alvo e faz commit/push
│   ├── OrchestratorJob.php              ← Despacha OrchestratorAgent → grava subtasks → enfileira workers
│   ├── ProcessSubtaskJob.php            ← Executa um SpecialistAgent para uma Subtask específica
│   ├── QAAuditJob.php                   ← Executa QAAuditorAgent sobre o diff de uma subtask
│   ├── SecurityAuditJob.php             ← Executa SecuritySpecialist após QA aprovar
│   ├── PerformanceAnalysisJob.php       ← Executa PerformanceAnalyst após Security aprovar
│   ├── ContextCompressionJob.php        ← Comprime sessão quando atinge threshold 0.6
│   ├── GenerateProjectSpecificationJob.php ← Gera spec técnica (legado)
│   ├── GenerateTasksFromSpecJob.php     ← Cria tasks a partir de uma ProjectSpecification aprovada (legado)
│   ├── GenerateProjectQuotationJob.php  ← Gera orçamento
│   └── ScaffoldProjectJob.php           ← Scaffolding inicial do Projeto Alvo
│
├── Events/
│   ├── TaskCreatedEvent.php             ← Disparado quando uma nova task é inserida
│   ├── SubtaskCompletedEvent.php        ← Disparado quando um subagente termina
│   ├── TaskAuditPassedEvent.php         ← Disparado quando QA aprova
│   ├── SecurityAuditPassedEvent.php     ← Disparado quando Security Specialist aprova
│   ├── SecurityVulnerabilityEvent.php   ← Disparado quando vulnerabilidade é detectada
│   └── TaskEscalatedEvent.php           ← Disparado quando retentativas estouraram
│
├── Listeners/
│   ├── DispatchOrchestratorListener.php   ← Escuta TaskCreatedEvent → despacha OrchestratorAgent
│   ├── QADispatcherListener.php           ← Escuta SubtaskCompletedEvent → verifica se todas terminaram
│   ├── SecurityDispatcherListener.php     ← Escuta TaskAuditPassedEvent → despacha SecuritySpecialist
│   ├── PerformanceDispatcherListener.php  ← Escuta SecurityAuditPassedEvent → despacha PerformanceAnalyst
│   ├── TaskCompletionListener.php         ← Escuta completion → CI/CD + vetorizar via pgvector
│   ├── VulnerabilityHandler.php           ← Escuta SecurityVulnerabilityEvent → cria subtask de correção
│   ├── EscalationNotifier.php             ← Escuta TaskEscalatedEvent → notifica humano via UI
│   └── ProblemSolutionRecorder.php        ← Grava na tabela problems_solutions
│
├── Services/
│   ├── SystemContextService.php     ← Monta contexto dinâmico (padrões TALL + RAG + histórico) para a mensagem user do prompt()
│   ├── ProjectBlueprintService.php  ← Normaliza e incorpora contribuições de módulo ao Blueprint Técnico Global
│   ├── ProjectRepositoryService.php ← Configura origin via projects.github_repo, exporta docs/PRDs e executa commit/push no alvo
│   ├── FileLockManager.php          ← Mutex de arquivos para subtasks paralelas (Fase 2 — planejado)
│   ├── PRDValidator.php             ← Valida PRD contra o JSON Schema (Fase 2 — planejado)
│   └── TaskOrchestrator.php         ← Coordena o pipeline Agent→QA→Git (Fase 2 — planejado)
│
├── Models/
│   ├── Project.php
│   ├── ProjectModule.php
│   ├── ProjectFeature.php            ← features geradas por IA (backend|frontend)
│   ├── ProjectSpecification.php
│   ├── ProjectQuotation.php
│   ├── Task.php
│   ├── Subtask.php
│   ├── AgentConfig.php
│   ├── TaskTransition.php
│   ├── SocialAccount.php             ← credenciais de redes sociais (CRUD implementado)
│   ├── SystemSetting.php             ← configurações do sistema via UI (AI providers, modelos, flags)
│   ├── ToolCallLog.php               ← migration existe; listener pendente
│   ├── ContextLibrary.php            ← 🔜 Fase 3 — não implementado ainda
│   ├── AgentExecution.php            ← 🔜 Fase 2 — não implementado ainda
│   ├── ProblemSolution.php           ← 🔜 Fase 3 — não implementado ainda
│   └── WebhookConfig.php             ← 🔜 Fase 2 — não implementado ainda
│
├── Enums/
│   ├── TaskStatus.php               ← pending, in_progress, qa_audit, testing, completed, etc.
│   ├── SubtaskStatus.php            ← pending, running, qa_audit, success, error, blocked
│   ├── AgentProvider.php            ← openrouter, ollama
│   ├── TaskSource.php               ← manual, specification, webhook, sentinel, ci_cd
│   ├── KnowledgeArea.php            ← backend, frontend, database, filament, devops, security, performance
│   └── SecuritySeverity.php         ← critical, high, medium, low, informational
│
└── Filament/
    └── Resources/
        ├── ProjectResource.php              ← CRUD + infolist com tabs (Visão Geral, Módulos, PRD, Orçamento)
        │                                      Aba Módulos: hierarquia colapsável (rootModules → Nível 1 → Nível 2)
        │                                      Botões: Gerar PRD, Gerando PRD... (desabilitado), Ver PRD Completo,
        │                                              Aprovar PRD, Auto Aprovar PRD — Cascata Completa
        ├── ProjectModuleResource.php        ← CRUD de módulos/submódulos + ações de PRD
        │                                      $shouldRegisterNavigation = false (oculto do sidebar)
        │                                      Botões: Gerar PRD, Gerando PRD... (desabilitado), Ver PRD Completo,
        │                                              Aprovar PRD (cria submódulos ou tasks), Iniciar/Concluir
        ├── ProjectSpecificationResource.php ← CRUD de especificações (legado)
        ├── ProjectQuotationResource.php     ← CRUD de orçamentos
        ├── TaskResource.php                 ← CRUD de tasks + visualização de status em tempo real
        ├── AgentConfigResource.php          ← Configuração de agentes (system prompts, modelos)
        ├── SocialAccountResource.php        ← CRUD de contas sociais por projeto (postagem pendente)
        ├── RoleResource.php                 ← Gestão de roles Spatie (FilamentShield)
        ├── ContextLibraryResource.php       ← 🔜 Fase 3 — Gestão dos padrões de código TALL
        └── Widgets/
            ├── TaskBoardWidget.php    ← Dashboard Kanban com status das tasks
            ├── CostTrackerWidget.php  ← Gráfico de custo por agente/período
            └── AgentHealthWidget.php  ← Status dos workers/filas em tempo real
```

---

## 4. Automação Agêntica Robusta: Fluxo Lógico e Auditoria (O Cérebro e o Juiz)

Para garantir que a automação não se torne um "prompt chain" livre e alucinado, o AI-Dev adota **Orquestração Determinística (State-Driven)**. O fluxo é rigidamente guiado pela máquina de estados do PostgreSQL, impedindo loops infinitos. 

Além disso, adotamos a classificação oficial de **Padrões de Agentes Claros**:
1. **`ORCHESTRATOR` (Planner)**: O planejador central estático. Recebe o PRD principal e o quebra em Sub-PRDs focados.
2. **`QA_AUDITOR` (Validator/Judge)**: O juiz implacável. Audita toda saída gerada comparando-a estritamente contra o PRD fornecido.
3. **`SUBAGENTES` (Executors)**: Os especialistas dinâmicos (Backend, Frontend, etc.) focados apenas em agir.

**Contratos Estritos para Ferramentas (Tool Layer/MCP):**
Todas as ações que interagem com o sistema (ler arquivo, executar comando) são feitas por meio de *Tools* com schemas JSON rigorosamente validados, eliminando falhas por chamadas de parâmetros inexistentes. Cada Tool possui um JSON Schema de entrada e saída documentado em `FERRAMENTAS.md`.

### 4.1. Ciclo de Vida da `Task` (Design Fail-Safe e Action-Driven)

O AI-Dev abandona o "Heartbeat Temporal" (loops a cada X minutos que gastam tokens lendo a mesma coisa sem agir). O sistema adota o **Action-Driven Heartbeat**: o ciclo de contexto e planejamento só avança após ações concretas (ex: a cada N tool calls) ou eventos reais via Webhooks, evitando requisições vazias.

```text
EVENTO GATILHO (Webhook/Nova Tarefa na UI/Sentinela):
1. [BUSCA] Ler tabela `tasks` WHERE status = 'pending' ORDER BY priority DESC LIMIT 1.
   → Usa SELECT ... FOR UPDATE para evitar que dois workers peguem a mesma task.

2. [LOCK] Mudar status da task para 'in_progress'. Registrar em task_transitions.
   → Criar branch Git: `git checkout -b task/{task_id_short}` no diretório do projeto.
   → Gravar branch name em tasks.git_branch.

3. [MEMÓRIA & CONTEXTO]
   a. *(Fase 3 — quando `problems_solutions` estiver implementado)* Busca semântica via `SimilaritySearch::usingModel(ProblemSolution::class)` — tool SDK que o agente chama dinamicamente quando necessário. Retorna os Top 3 problemas+soluções com similaridade de cosseno > 0.7. O agente decide quando usar com base na complexidade do PRD — não é injeção estática obrigatória.
   b. Consultar `context_library` WHERE knowledge_area IN (áreas da task) AND is_active = true.
      → Carrega os padrões de código TALL que o agente DEVE seguir.
   c. Carregar histórico de conversa via `RemembersConversations` (SDK nativo — tabela `agent_conversations`).
      → `AgentClass::make()->continueLastConversation($user)->prompt(...)` resgata automaticamente
        as últimas 100 mensagens da conversa persistida no PostgreSQL.
   d. Compilar o [Contexto Global] juntando: Padrões TALL + Soluções passadas + Histórico.

4. [PLANEJAMENTO VIA PRD] (Planner: 'ORCHESTRATOR')
   → O OrchestratorJob monta o prompt:
     [System Prompt do Orchestrator] + [Contexto Global] + [PRD Principal da Task]
   → Envia para o LLM (Claude Opus 4.7 via OpenRouter — modelo de planejamento do sistema).
   → O LLM responde com a lista de Sub-PRDs estruturados em JSON.
   → O OrchestratorJob valida cada Sub-PRD contra o JSON Schema (via PRDValidator).
   → Insere múltiplas Subtasks na tabela `subtasks`, cada uma com:
     - O sub_prd_payload (Mini-PRD focado)
     - O assigned_agent correto (ex: backend-specialist para migrations)
     - As dependencies (ex: subtask de migration vem ANTES do subtask de model)
     - O execution_order (1, 2, 3...)
   → Registra transição em task_transitions.

5. [VERIFICAÇÃO DE DEPENDÊNCIAS E FILE LOCKS] (FileLockManager)
   → Para cada subtask criada, o FileLockManager analisa quais arquivos provavelmente 
     serão tocados (baseado no sub_prd e no assigned_agent).
   → Se duas subtasks tocam o MESMO arquivo, elas NÃO podem rodar em paralelo.
     → A segunda recebe dependencies = [id_da_primeira] automaticamente.
   → Subtasks que tocam arquivos DIFERENTES rodam em paralelo sem restrição.
   
   Exemplo prático:
   - Subtask A (backend-specialist): "Criar Model User.php" → toca app/Models/User.php
   - Subtask B (database-specialist): "Criar Migration create_users" → toca database/migrations/
   - Subtask C (filament-specialist): "Criar UserResource" → toca app/Filament/
   → A e B podem rodar em paralelo (arquivos diferentes).
   → C depende de A (precisa do Model antes de criar o Resource).

6. [EXECUÇÃO PARALELA DOS SUBAGENTES] (Executors)
   Para cada Subtask na fila (respeitando dependências via execution_order):
     a. Verificar: todas as subtasks em `dependencies` estão com status `success`?
        → NÃO: Manter status `pending` e esperar.
        → SIM: Avançar para execução.
     b. O ProcessSubtaskJob monta o Prompt:
        [System Prompt do Agente (agents_config.role_description)]
        + [Padrões de Código relevantes (context_library)]
        + [Sub-PRD desta subtask (subtasks.sub_prd_payload)]
        + [Soluções passadas relevantes (problems_solutions via RAG)]
     c. Enviar para o LLM configurado para este agente via `$agent->prompt(...)` (Laravel AI SDK).
        → O provider e model são definidos por PHP Attributes (`#[Provider]`, `#[Model]`) ou
          pela tabela `agents_config` lida em runtime via `instructions()`.
     d. O LLM responde com tool calls — o SDK despacha automaticamente para `handle(Request $request)`.
     e. O SDK valida cada tool call contra o `schema(JsonSchema $schema)` da ferramenta.
        → Se o schema falhar: o SDK retorna o erro estruturado ao LLM para que corrija.
     f. O SDK executa as tool calls via as classes em `app/Ai/Tools/` (implementam `Tool` contract).
     g. Repetir o ciclo (LLM ↔ SDK ↔ Tools) até o LLM sinalizar "tarefa concluída".
     h. Ao finalizar:
        → Gerar `git diff` do que foi alterado e salvar em subtasks.result_diff.
        → Listar arquivos modificados em subtasks.files_modified.
        → Marcar status como 'qa_audit'.
        → Disparar SubtaskCompletedEvent.

7. [AUDITORIA LOCAL POR SUBTASK] (Judge: 'QA_AUDITOR')
   → O QAAuditJob recebe os IDs das subtasks para auditar.
   → Para CADA subtask com status 'qa_audit':
     → Monta o prompt:
       [System Prompt do QA] + [Sub-PRD ORIGINAL] + [git diff gerado pelo subagente]
       + [Resultado da execução (logs, erros)]
     → Pergunta ao LLM:
       "O código gerado atende ESTRITAMENTE a TODOS os critérios do Sub-PRD?
        Os padrões TALL foram seguidos? Existem bugs óbvios?"
     → O LLM responde com um JSON estruturado (schema canônico em PROMPTS.md §3.2):
       {
         "approved": true/false,
         "criteria_checklist": [{"criterion": "...", "passed": true/false, "note": "..."}],
         "issues": [{"file": "...", "line": N, "severity": "critical|minor|cosmetic", "description": "...", "suggestion": "..."}],
         "overall_quality": "excellent|good|acceptable|poor",
         "recommendation": "approve|fix_and_retry|escalate_to_human"
       }
     → Se APROVADO:
       → Marcar subtask como 'success'. Registrar em task_transitions.
     → Se REJEITADO:
       → Incrementar subtask.retry_count.
       → Se retry_count < max_retries (default: 3):
         → Salvar feedback em subtask.qa_feedback.
         → Reverter status para 'pending'.
         → Despachar novo ProcessSubtaskJob com o feedback do QA incluído no prompt.
         → O subagente corrige o código baseado EXATAMENTE no feedback do QA.
       → Se retry_count >= max_retries:
         → Marcar subtask como 'error'.
         → Se TODAS as subtasks essenciais estão em 'error':
           → Escalar task para 'escalated'. Disparar TaskEscalatedEvent.
           → O EscalationNotifier envia notificação na UI Filament.
           → Humano intervém via interface, corrige manualmente ou redefine o PRD.

8. [INTEGRAÇÃO E AUDITORIA GLOBAL] 
   → Quando TODAS as subtasks de uma task estão com status 'success':
     → O Orchestrator faz um merge FINAL de todas as alterações no branch da task.
     → O QA Auditor faz a checagem MACRO: o conjunto de todas as alterações 
       atende ao PRD PRINCIPAL? (Não apenas individualmente, mas como um todo.)
     → Se PASSAR: Avançar para AUDITORIA DE SEGURANÇA.
     → Se FALHAR: Criar subtask de correção pontual.

9. [AUDITORIA DE SEGURANÇA] (Security Specialist: 'security-specialist')
   → O SecurityAuditJob é despachado AUTOMATICAMENTE após o QA aprovar.
   → Este é um passo OBRIGATÓRIO — nenhum código vai para produção sem passar aqui.
   → O Security Specialist executa 5 camadas de verificação:

     Camada 1: Análise Estática do Código (SAST)
     → Roda `php artisan enlightn` no projeto (Enlightn OSS — 66 checks gratuitos)
       → Verifica: debug mode em produção, cookies inseguros, mass assignment, 
         SQL injection por concatenação, headers de segurança faltando
     → Roda Larastan/PHPStan nível 6+ (`./vendor/bin/phpstan analyse`)
       → Verifica: types incorretos, variáveis undefined, imports faltando,
         chamadas de método inválidas (bugs que viram brechas de segurança)

     Camada 2: Auditoria de Dependências (SCA)
     → Roda `composer audit` (nativo do Composer 2.4+)
       → Verifica: pacotes com CVEs conhecidas no composer.lock
     → Roda `npm audit --json` 
       → Verifica: pacotes npm com vulnerabilidades conhecidas
     → Se encontrar CVE de severidade CRITICAL ou HIGH:
       → BLOQUEIA o deploy. Cria subtask de atualização do pacote.
       → Dispara SecurityVulnerabilityEvent.

     Camada 3: Verificação OWASP Top 10 via LLM
     → O Security Specialist (Claude Sonnet 4-6) recebe o git diff completo e analisa:
       1. Injection (SQL, XSS, Command Injection) — Busca por DB::raw(), {!! !!}, exec()
       2. Broken Authentication — Verifica middleware 'auth' em rotas protegidas
       3. Sensitive Data Exposure — Busca por credenciais hardcoded, .env em público
       4. Mass Assignment — Verifica $guarded / $fillable nos Models
       5. Broken Access Control — Verifica Policies/Gates em Resources Filament
       6. Security Misconfiguration — APP_DEBUG=true, APP_ENV=production
       7. Cross-Site Scripting (XSS) — Busca por {!! $var !!} sem sanitização
       8. Insecure Deserialization — Busca por unserialize() em inputs do usuário
       9. Insufficient Logging — Verifica se ações críticas têm log (login, delete)
       10. SSRF — Busca por file_get_contents($userInput) ou curl com URL dinâmica

     Camada 4: Scan de Servidor Web (DAST — Dinâmico)
     → Roda Nikto contra o endpoint DO PROJETO ALVO (não do AI-Dev):
       `nikto -h http://{project_url} -o /tmp/nikto_report.txt -Format txt`
       → Verifica: versões outdated de software, headers expostos, diretórios sensíveis
     → (Fase 3): Roda OWASP ZAP em modo headless para scan mais profundo

     Camada 5: Teste de SQL Injection Automatizado
     → Roda SQLMap em modo não-destrutivo (--batch --level=1 --risk=1) contra forms do projeto:
       `python3 sqlmap.py -u "http://{project_url}/login" --forms --batch --level=1 --risk=1`
       → SOMENTE em ambiente de staging/development. NUNCA em produção.
       → Se detectar vulnerabilidade: BLOQUEIA deploy + cria subtask de correção.

   → Resultado do Security Specialist:
     {
       "passed": true/false,
       "vulnerabilities": [
         {"type": "sql_injection", "file": "app/Http/Controllers/PostController.php", 
          "line": 45, "severity": "critical", "description": "DB::raw() com input não sanitizado",
          "remediation": "Usar query builder com bindings: ->where('title', '=', $input)"}
       ],
       "enlightn_score": 85,
       "dependencies_ok": true/false,
       "nikto_findings": 2,
       "overall_risk": "low|medium|high|critical"
     }

   → Se PASSAR (overall_risk = low): Avançar para ANÁLISE DE PERFORMANCE.
   → Se FALHAR (overall_risk >= medium):
     → Criar subtasks de correção de segurança (prioridade MÁXIMA).
     → O subagente recebe a vulnerabilidade EXATA + remediation sugerida.
     → Após correção: volta ao passo 7 (QA) → 9 (Security) de novo.
   → Se CRITICAL e irrecuperável: Escalar para humano via Filament.

10. [ANÁLISE DE PERFORMANCE] (Performance Analyst: 'performance-analyst')
    → Disparado automaticamente APÓS a auditoria de segurança passar.
    → O PerformanceAnalysisJob executa:

      a. Detecção de N+1 Queries:
         → Instala/usa `beyondcode/laravel-query-detector` temporariamente
         → Roda requests simulados via `ShellExecuteTool` (curl) e testes Pest Browser contra as rotas do projeto
         → Cada query lazy-loaded é reportada com arquivo e linha
      
      b. Verificação de Índices Missing:
         → Para cada Model do projeto, analisa as queries mais comuns
         → Roda `EXPLAIN` nas queries e verifica se estão usando index scan
         → Sugere criação de índices via migration
      
      c. Browser Tests com Pest 4 (Validação Real):
         → Roda `php artisan test tests/Browser/ --compact` via `ShellExecuteTool`:
           - Preenche formulários com dados realistas via `visit()/fill()/click()`
           - Verifica ausência de erros de JavaScript (`assertNoJavaScriptErrors()`)
           - Smoke test em todas as rotas principais (`assertNoConsoleLogs()`)
         → Se os testes de browser falharem:
           - Captura o output do Pest + `BoostTool.browser-logs` (Telescope/Debugbar) para diagnóstico
           - Inclui o stack trace no relatório como contexto para análise
           - Cria subtask de correção com prioridade alta
      
      d. Análise de Tempo de Resposta:
         → Mede o tempo de resposta de cada rota principal do projeto
         → Rotas com > 500ms são flagadas para otimização
      
      e. Verificação de Cache:
         → Verifica se config/route/view estão cacheados em produção
         → Sugere `php artisan optimize` se não estiverem

    → Resultado do Performance Analyst:
      {
        "passed": true/false,
        "n_plus_1_queries": [{"file": "...", "line": 45, "model": "Post", "relation": "comments"}],
        "missing_indexes": [{"table": "posts", "column": "user_id", "query": "..."}],
        "browser_tests_passed": true/false,
        "browser_test_failures": ["..."],
        "slow_routes": [{"route": "/posts", "time_ms": 780}],
        "recommendations": ["Adicionar eager loading em PostController@index: Post::with('comments')"]
      }

    → Se PASSAR: Avançar para CI/CD.
    → Se n_plus_1 ou slow_routes detectados: Criar subtask de otimização (prioridade média).
    → Se browser_tests_passed = false: Criar subtask de correção de UI/JS (prioridade alta).
    → Otimizações não são bloqueantes (o deploy continua), mas geram tasks futuras.

11. [CI/CD & COMMIT]
    → O OrchestratorJob comanda o Git no diretório do projeto:
      a. `git add .`
      b. `git commit -m "feat(ai-dev): {task.title} [Task #{task.id_short}]"`
      c. `git checkout main` (ou branch principal)
      d. `git merge task/{task.id_short} --no-ff`
         → O --no-ff preserva o histórico do branch da task para rastreio.
      e. `git push origin main`
      f. `git branch -d task/{task.id_short}` (limpa o branch local)
    → Mudar status da task para 'testing'.
    → Registrar em task_transitions.

12. [FEEDBACK LOOP & SELF-HEALING (Auto-Correção Nativa)]

    O sistema possui TRÊS camadas de feedback implacáveis:

    **Camada 1: CI/CD Testing (Testes Unitários + Integração + Browser Logs)**
    → O servidor de testes roda a suite COMPLETA em 3 etapas:
      Etapa 1: `php artisan test --parallel` (Pest v4 — Feature + Unit)
      Etapa 2: `php artisan test tests/Browser/ --compact` (Pest 4 Browser — simulação real pós-merge)
      Etapa 3: `php artisan enlightn` (Enlightn — segurança + performance)
    → POR QUE rodar browser tests AQUI também (além do passo 10)?
      Porque o passo 10 testa o código no branch da task. O passo 12 testa
      APÓS o merge no main — pode haver conflitos que quebraram a aplicação.
      Os browser tests aqui validam que a aplicação COMPLETA funciona, não apenas a feature nova.
    → Se TODOS os testes passarem:
      → Task vai para 'completed'. Missão cumprida.
      → O ProblemSolutionRecorder salva PRD + solução no banco vetorial.
    → Se algum teste FALHAR:
      → O sistema cria uma NOVA Task automática com:
        - source = 'ci_cd'
        - priority = 90 (alta, mas não máxima)
        - prd_payload = stack trace do teste + arquivo do teste + assertion que falhou
      → O ciclo recomeça autonomamente.

    **Camada 2: O Sentinela (Runtime Self-Healing)**
    → Todo projeto gerado pelo AI-Dev terá um "Sentinela" embutido:
      um Exception Handler customizado no `bootstrap/app.php` do PROJETO ALVO.
    → O Sentinela NÃO é um pacote visual para humanos (como spatie/laravel-error-solutions).
    → Ele é um listener SILENCIOSO que intercepta qualquer Exception em RUNTIME:
      - Fatais (Error, TypeError)
      - Syntax Errors (ParseError)
      - Query Exceptions (QueryException, deadlocks)
      - HTTP Exceptions (404 em lote = rota quebrada)
    → Quando uma falha é detectada, o Sentinela faz uma chamada HTTP (via queue, não síncrona)
      para a API do AI-Dev Core, injetando uma Task de **Prioridade Máxima (100)** contendo:
      - O Stack Trace completo
      - A linha exata e o arquivo do erro
      - O request que causou o erro (URL, método, payload)
      - As últimas 5 queries SQL executadas (para contexto de DB)
    → O Orchestrator pega essa task ANTES de qualquer outra (prioridade 100).
    → Os agentes corrigem o código quebrado e fazem commit.
    → O Sentinela para de reportar aquele erro porque a exceção não ocorre mais.
    
    **Proteção contra Loop Infinito do Sentinela:**
    → Para evitar que o Sentinela crie tasks infinitamente para o MESMO erro:
      - Cada erro é hashado (hash do file + line + exception class).
      - Se o mesmo hash já existe numa task `in_progress` ou `pending`, 
        o Sentinela NÃO cria duplicata.
      - Se o mesmo hash tem 3+ tasks `failed`, o Sentinela para de reportar 
        e marca como `requires_human` no log, notificando via Filament UI.
```

---

## 5. Memória Persistente, Prompt Caching e Economia de Contexto

Em vez de salvar o histórico em um arquivo de texto (`memory.md`) que cresce eternamente e devora tokens, o AI-Dev adota **Gestão de Contexto via Banco de Dados Relacional (PostgreSQL)**. Isso permite buscar dados antigos sem embutir o histórico inteiro no *prompt*. No Laravel 13, utilizamos o SDK nativo `laravel/ai` para persistência automática em `agent_conversations`.

A gestão de contexto é focada em altíssima economia (inspirada no *Hermes Agent*):

### 5.1. Compressão Ativa de Contexto (Short-term) via Modelo Local

O Orchestrator e os Subagentes possuem uma **trava de compressão (threshold de 0.6)**. Quando a sessão atinge 60% do limite da janela de contexto, o sistema faz um reset forçado na sessão.

**Como funciona tecnicamente:**

```text
1. O SDK rastreia o uso de tokens via AgentResponse::usage() (campos: promptTokens, completionTokens).
   → Calculamos o ratio: (prompt_tokens / janela_maxima_do_modelo)
   → Ex: Se o Sonnet 4.6 tem janela de 200K tokens e o prompt está com 120K → ratio = 0.6

2. Quando ratio >= 0.6:
   → O ContextCompressionJob é despachado na fila "compressor".
   → Este Job chama: ContextCompressor::make()->prompt($historico_completo)
     → Usando Ollama (qwen2.5:0.5b) via Lab::Ollama — modelo local sem custo de API.
   → O modelo local gera um resumo denso (~500-1000 tokens) do histórico.
   → O resumo é salvo como nova instrução adicional na conversa (agent_conversations).
   → A conversa atual é finalizada e reiniciada com:
     [System Prompt] + [Resumo Comprimido como instrução adicional] + [Últimas 3 mensagens]
   → O agente continua trabalhando sem perceber a troca.

3. Por que o threshold é 0.6 e não 0.9?
   → Com 0.6, ainda sobram 40% da janela para o agente trabalhar antes da próxima compressão.
   → Com 0.9, o modelo já está degradado (atenção cai em janelas muito longas).
   → O sweet spot entre economia e qualidade é 0.6 baseado em testes empíricos.
```

### 5.2. Prompt Caching Nativo (Economia de até 90%)

Como todo o sistema agêntico roteia para a família Anthropic via OpenRouter (Opus 4.7 / Sonnet 4.6 / Haiku 4.5), o prompt é estruturado para maximizar o cache hit do Anthropic prompt cache:

**Como funciona:**

```text
O prompt enviado ao LLM é SEMPRE estruturado nesta ordem:

┌─────────────────────────────────────────────────────┐
│ BLOCO 1: ESTÁTICO (Cacheável — Não muda entre calls) │
│                                                       │
│ [System Prompt do Agente]                             │
│ [Padrões TALL (context_library)]                      │
│ [Documentação Fixa do Filament v5]                    │
│                                                       │
│ 💰 Este bloco é lido do cache na 2ª chamada em diante │
│    Economia: ~90% nos tokens de entrada deste bloco   │
└─────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────┐
│ BLOCO 2: SEMI-ESTÁTICO (Muda por task)                │
│                                                       │
│ [Soluções passadas relevantes (RAG)]                  │
│ [Contexto comprimido da sessão anterior]              │
│                                                       │
│ 💰 Pode ter cache parcial se a mesma task retry       │
└─────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────┐
│ BLOCO 3: DINÂMICO (Muda a cada chamada LLM)           │
│                                                       │
│ [PRD/Sub-PRD específico]                              │
│ [Resultado da tool call anterior]                     │
│ [Feedback do QA (se retry)]                           │
│                                                       │
│ ❌ Este bloco NUNCA é cacheado                         │
└─────────────────────────────────────────────────────┘

Por que essa ordem importa?

A API da Anthropic (roteada via OpenRouter) identifica blocos cacheáveis 
pela POSIÇÃO no prompt. Se os primeiros milhares de tokens forem IDÊNTICOS entre 
duas chamadas, o provedor usa a versão em cache e cobra ~1/10 do preço.

Ao colocar conteúdo estático PRIMEIRO e dinâmico por ÚLTIMO, maximizamos 
a chance de cache hit. Se fizéssemos o contrário (PRD primeiro, System Prompt depois),
o cache NUNCA seria aproveitado porque o início do prompt mudaria a cada chamada.
```

**Implementação pelo Laravel AI SDK:**
Os blocos 1 e 2 (estático + semi-estático) são entregues pelo método `Agent::instructions()` e ficam no `system prompt` do SDK — o Laravel AI SDK aplica `cache_control: {"type": "ephemeral"}` automaticamente para os blocos longos de `system`, e o OpenRouter repassa a flag transparentemente para o backend Anthropic. O Bloco 3 (dinâmico) é concatenado ao argumento do `->prompt($subPrd . $toolResult . $qaFeedback)` e vai como mensagem `user`, portanto nunca é cacheado — exatamente o comportamento desejado.

### 5.3. RAG Vetorial via pgvector (Long-term)

*   **O que salvar:** Sempre que uma `Task` finaliza com sucesso, o PRD original e o *diff* do código vencedor são vetorizados e salvos na tabela `problems_solutions`.
*   **Como gerar embeddings:** Via Laravel AI SDK (`AI::embeddings()->provider(Lab::OpenAI)->embed(...)`) ou modelo local via Ollama. O SDK suporta múltiplos provedores de embeddings (OpenAI, Gemini, Cohere, Jina, VoyageAI).
*   **Onde armazenar:** Coluna `vector(1536)` na tabela `problems_solutions` via **pgvector** nativo no PostgreSQL 16. Eliminamos a necessidade de ChromaDB ou SQLite-Vec — busca vetorial nativa no mesmo banco relacional.
*   **Como usar:** O Laravel AI SDK fornece a tool nativa `SimilaritySearch`. Em vez de injetar estaticamente no prompt, o agente recebe a ferramenta `SimilaritySearch::usingModel(ProblemSolution::class)` em seu array de tools. O próprio agente decide quando pesquisar por soluções passadas com base na complexidade do problema, delegando a execução vetorial (`whereVectorSimilarTo`) para o SDK de forma transparente.
*   **Exemplo prático:** O agente backend recebe uma task complexa de WebSockets. Ele chama a tool `SimilaritySearch` com a query "WebSocket Reverb setup". O SDK executa a busca vetorial no pgvector e devolve os exemplos passados diretamente para o agente.

---

## 6. Engenharia de Prompts e Injeção de Padrões

O AI-Dev adota diretrizes estritas para a construção do *System Prompt*, baseadas no cruzamento relacional das tabelas de conhecimento e na economia agressiva de tokens. O documento completo está em `PROMPTS.md`.

### 6.1. Injeção Dinâmica Baseada em Áreas de Conhecimento

A tabela `agents_config` possui o campo `knowledge_areas` (JSON array de áreas).
O **`SystemContextService`** (em `app/Services/`) usa isso para fazer uma "Injeção Cirúrgica" antes de chamar `->prompt()`:

**Exemplo concreto:**

```text
Cenário: Task com PRD sobre "Erro de Layout no dashboard do Filament"

1. O SystemContextService detecta as knowledge_areas relevantes: ["frontend", "filament"]

2. Consulta context_library WHERE knowledge_area IN ("frontend", "filament")
   → Retorna: Padrão "Resource Filament v5 com Tabs", Padrão "Widget de Dashboard"

3. Consulta problems_solutions WHERE knowledge_area IN ("frontend", "filament")
   → Retorna: Solução passada "CSS conflito com Tailwind dark mode"

4. NÃO consulta nada de area "database" ou "devops"
   → O agente frontend NÃO recebe lixo de contexto sobre SQL
   → Economia de tokens e foco total

5. O prompt montado fica:
   [System Prompt do frontend-specialist]
   [Padrão: Resource Filament v5 com Tabs]
   [Padrão: Widget de Dashboard]
   [Solução passada: CSS conflito com Tailwind dark mode]
   [Sub-PRD: Corrigir layout do dashboard]
```

### 6.2. Hierarquia do System Prompt por Agente

Todo agente recebe um System Prompt composto de 4 camadas concatenadas:

```text
Camada 1: REGRAS UNIVERSAIS (iguais para todos os agentes)
  → Tool-Use Enforcement, Act Don't Ask, Verification
  → Definidas em PROMPTS.md seções 1.1, 1.2, 1.3

Camada 2: REGRAS DO PROVEDOR (específicas da família Anthropic via OpenRouter)
  → Caminhos absolutos sempre (nunca cwd implícito)
  → Tool-use com JSON estrito (Anthropic input_schema)
  → Evitar abandono da task, recuperação de falha com retry
  → Definidas em PROMPTS.md seção 2

Camada 3: ROLE (específica do tipo de agente)
  → O texto em agents_config.role_description
  → Ex: "Você é um especialista em backend Laravel 13. Sua responsabilidade
         é criar Controllers, Models, Services e Migrations..."

Camada 4: CONTEXTO DINÂMICO (montado em runtime pelo SystemContextService)
  → Padrões TALL relevantes (context_library)
  → Soluções passadas (problems_solutions via RAG)
  → Histórico carregado automaticamente pelo SDK (trait RemembersConversations)
  → Injetado na mensagem user do Agent->prompt() — fora de cache por design
```

### 6.3. Motores de IA e Gestão de Sessão (Contexto Infinito por Projeto)

O AI-Dev suporta **múltiplos providers de IA configuráveis dinamicamente** via System Settings UI (`SystemSettingsPage`). O `AiRuntimeConfigService` resolve provider, model e API key em runtime a partir da tabela `system_settings`, eliminando hardcodes. Cada tier (Premium, High, Fast, System) pode usar qualquer provider registrado: OpenRouter, Anthropic, OpenAI, Kimi (Moonshot AI) ou Ollama.

*   **Premium (Planejamento):** `OrchestratorAgent`, `SpecificationAgent`, `QuotationAgent` — usam o provider/model configurados em `AI_PREMIUM_*`.
*   **High (Código/QA):** `SpecialistAgent`, `QAAuditorAgent` — usam o provider/model configurados em `AI_HIGH_*`.
*   **Fast (Docs):** `DocsAgent` — usa o provider/model configurados em `AI_FAST_*`.
*   **System (Chat):** `SystemAssistantAgent`, `DashboardChat` — usa o provider/model configurados em `AI_SYSTEM_*`.

**Providers suportados:**

| Provider | Driver | Endpoint | Observação |
|---|---|---|---|
| `openrouter` | `openrouter` | `https://openrouter.ai/api/v1` | Gateway universal, família Anthropic |
| `anthropic` | `anthropic` | `https://api.anthropic.com` | Direto |
| `openai` | `openai` | `https://api.openai.com/v1` | Direto |
| `kimi` | `kimi` | `https://api.kimi.com/coding/v1` | Kimi Code (planos de membresia) |
| `ollama` | `ollama` | `http://localhost:11434` | Local |

O provider `kimi` requer um driver customizado (`App\Ai\Providers\KimiProvider`) porque o Prism v0.100+ usa o endpoint `/responses` (API nova OpenAI) que a Moonshot não suporta. O `KimiProvider` força o driver para `openrouter` no Prism, garantindo o uso do endpoint `/chat/completions` compatível, e injeta um `User-Agent` whitelistado via middleware HTTP global (`AppServiceProvider::boot()`). O model ID aceito é `kimi-k2.6` (ou `kimi-for-coding` — ambos mapeiam para o mesmo modelo no backend).

**Compatibilidade com Kimi K2.6 (Thinking Mode + Tool Calls):**
O modelo `kimi-k2.6` tem *thinking mode* habilitado por padrão. Quando usa *tool calls*, a API retorna o raciocínio no campo `reasoning_content` (não em `content`). O Prism descarta esse campo ao reenviar o histórico, o que causava erro 400 da API. A solução implementada é um **cache de `reasoning_content` no middleware HTTP** (`AppServiceProvider`):
1. Ao receber uma resposta da API com `tool_calls` + `reasoning_content`, o middleware armazena o valor em cache indexado pelo `tool_call_id`.
2. Na próxima requisição, injeta o `reasoning_content` correto em cada mensagem de `assistant` que contenha `tool_calls`.

Além disso, o `SystemAssistantAgent` possui o atributo `#[MaxSteps(10)]` porque o Kimi K2.6 precisa de mais steps para explorar arquivos via `FileReadTool` e depois retornar a resposta final (`finish_reason = stop`). O padrão do Prism (`round(count(tools) * 1.5) = 3`) era insuficiente.

*   **Ollama (Compressor Local):** Modelo ultraleve rodando permanentemente no servidor (`qwen2.5:0.5b` ou `llama3.2:1b` — ambos cabem em ~500MB de RAM). Sua ÚNICA função é comprimir contexto quando a janela atinge 60% e gerar embeddings — nunca é usado para gerar código ou planejar. Fase 3 do roadmap.

*   **Gestão de Contexto:** O conversation ID ativo é armazenado na tabela `projects` (`anthropic_session_id`) e gerenciado pelo trait `RemembersConversations` do SDK. A cada nova chamada, `AgentClass::make()->continue($conversationId, $user)->prompt(...)` resgata automaticamente o histórico do PostgreSQL.

**Seleção de Motor via Laravel AI SDK + `AiRuntimeConfigService`:**

```php
// Em qualquer Job ou Widget:
$aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);
$response = OrchestratorAgent::make()->prompt(
    $prompt,
    provider: $aiConfig['provider'],
    model: $aiConfig['model'],
);
```

O `AiRuntimeConfigService::apply()`:
1. Lê provider/model/key do `SystemSetting`
2. Injeta a key em `config("ai.providers.{$provider}.key")`
3. Limpa o cache do provider no `AiManager` (`Ai::forgetInstance()`) para garantir que a nova key seja usada

Hierarquia de seleção: chamada explícita via `AiRuntimeConfigService` > PHP Attribute > `config('ai.default')`

Os PHP Attributes `#[Provider]` e `#[Model]` nas classes Agent existem como fallback, mas **todos os Jobs e widgets do sistema usam `AiRuntimeConfigService` em runtime**, tornando a configuração 100% dinâmica.
```

---

## 7. Arsenal de Ferramentas (The Tool Layer) e MCP Isolado

As ferramentas são classes PHP em `app/Ai/Tools/` que implementam o contrato `Tool` do **Laravel AI SDK** (`laravel/ai`). O SDK gerencia automaticamente o dispatch das tool calls via `handle(Request $request)`, validando parâmetros contra o `schema(JsonSchema $schema)` de cada ferramenta — sem necessidade de ToolRouter custom.

O catálogo completo de ferramentas, com schemas de entrada/saída e exemplos práticos, está documentado em `FERRAMENTAS.md`. Abaixo temos o resumo consolidado:

### Ferramentas Atômicas (6 Tools — `implements Laravel\Ai\Contracts\Tool`)

| # | Ferramenta | Classe | Ações Principais |
|---|---|---|---|
| 1 | **BoostTool** | `App\Ai\Tools\BoostTool` | Ponte para o Laravel Boost MCP do Projeto Alvo: `database-schema`, `search-docs`, `browser-logs`, `last-error`, `database-query` |
| 2 | **DocSearchTool** | `App\Ai\Tools\DocSearchTool` | Busca focada em docs TALL Stack via Boost `search-docs` (wrapper usado pelo `DocsAgent`) |
| 3 | **FileReadTool** | `App\Ai\Tools\FileReadTool` | Leitura de arquivos do Projeto Alvo com limites de linhas/bytes |
| 4 | **FileWriteTool** | `App\Ai\Tools\FileWriteTool` | Escrita/patch de arquivos do Projeto Alvo com validação de path |
| 5 | **GitOperationTool** | `App\Ai\Tools\GitOperationTool` | `status`, `diff`, `add`, `commit`, `push`, `branch` no repo do Projeto Alvo |
| 6 | **ShellExecuteTool** | `App\Ai\Tools\ShellExecuteTool` | Execução allowlisted de `php artisan`, `composer`, `npm`, `npx` e binários QA no cwd do Projeto Alvo |

**Por que apenas 6?** O contrato `Tool` do Laravel AI SDK encoraja ferramentas enxutas com `schema()` preciso — cada Tool é uma classe PHP com ações bem definidas. Ferramentas especializadas (testes, segurança, performance, deploy, redes sociais) são cobertas pela combinação `ShellExecuteTool` + `BoostTool` no Projeto Alvo: `php artisan test`, `php artisan enlightn`, `composer audit`, etc. rodam via shell; schema e logs vêm do Boost. Funcionalidades futuras (publicação em redes sociais, análise dinâmica) serão adicionadas como Agents dedicados, não como Tools genéricas.

---

## 8. Estratégia de Rollback e Recovery (Plano de Contingência)

Este capítulo define o que acontece em **cenários de falha** — algo que muitos sistemas multi-agente simplesmente ignoram. O AI-Dev trata falhas como parte normal do fluxo.

### 8.1. Isolamento por Git Branch (Proteção Principal)

**Toda task roda em seu próprio branch Git.** Isso significa que se uma task der errado, o branch `main` não é afetado.

```text
Fluxo Git de uma Task:

1. Task criada → git checkout -b task/a1b2c3d4 (criado a partir de main)
2. Subagentes trabalham → commits parciais no branch da task
3. QA aprova → git merge task/a1b2c3d4 --no-ff into main
4. Se QA reprova e esgotam retries → git checkout main (abandonar o branch)
5. Se CI falha após merge → git revert HEAD (reverter o merge commit)
```

**Por que isso resolve o problema?** No modelo anterior (sem branches), uma task com falha poderia poluir o `main` com código incompleto. Com branches isolados, o `main` só recebe código **100% aprovado pelo QA e testado**.

### 8.2. Cenários de Falha e Ações Automáticas

| Cenário | Ação do Sistema |
|---|---|
| **Subagente falha (erro do LLM)** | Retry automático até `max_retries` (3). Último log gravado em `subtask.result_log` |
| **QA rejeita a entrega** | Subagente recebe feedback do QA e tenta corrigir. Até 3 retries |
| **Retentativas esgotadas** | Task vai para `escalated`. Notifica humano via Filament com todo o contexto |
| **Servidor cai durante task** | O Supervisor reinicia os workers. A task permanece `in_progress` no DB. O Job é re-despachado automaticamente pelo Laravel Queue (retry built-in) |
| **Git push falha** | `ProjectRepositoryService` tenta `git pull --rebase` + `git push` novamente. Se houver conflito ou erro de credencial, registra warning para intervenção humana |
| **API do LLM fora do ar** | O SDK faz failover automático via `AgentFailedOver` event. Como todo o tráfego passa por OpenRouter, se o modelo primário (ex: Opus 4.7) falhar, o `fallback_agent_id` em `agents_config` aponta para um fallback dentro da família Anthropic (ex: Sonnet 4.6) — todos via OpenRouter |
| **Duas subtasks editam o mesmo arquivo** | FileLockManager impede (status `blocked`). Nunca acontece race condition |
| **Sentinela em loop (mesmo erro)** | Hash de dedup impede criação de tasks duplicadas. Após 3 falhas: `requires_human` |
| **Compressão de contexto corrompida** | O histórico completo fica em disco (`full_history_path`). Pode ser restaurado manualmente |
| **Modelo local (Ollama) offline** | ContextCompressionJob faz retry com backoff exponencial. Se Ollama não voltar em 5 min, usa Haiku 4.5 via OpenRouter como fallback para comprimir (mais caro que local, mas funciona) |

### 8.3. Limites Explícitos (Circuit Breakers)

```text
MAX_SUBTASK_RETRIES = 3          # Subtask refaz no máximo 3 vezes
MAX_TASK_RETRIES = 3             # Task inteira (re-plan) no máximo 3 vezes
MAX_SENTINEL_SAME_ERROR = 3     # Sentinela para de reportar após 3 falhas do mesmo hash
MAX_CONTEXT_TOKENS_RATIO = 0.6  # Compressão dispara em 60% da janela
MAX_COST_PER_TASK_USD = 5.00    # Se o custo de uma task ultrapassar $5, pausa e escala
MAX_TOOL_CALLS_PER_TURN = 50    # Se um agente fizer 50 tool calls sem progresso, aborta
MAX_EXECUTION_TIME_MINUTES = 30 # Se uma subtask rodar por mais de 30min, mata e retry
```

---

## 9. Métricas, Observabilidade e Dashboard

O AI-Dev não opera "no escuro". Todo comportamento do sistema é observável via dashboard Filament e logs estruturados.

### 9.1. Métricas Rastreadas

| Métrica | Fonte | Uso |
|---|---|---|
| **Tokens consumidos/agente/período** | `agent_executions` | Identificar agentes "caros" que precisam de prompt mais enxuto |
| **Taxa de sucesso/falha por agente** | `task_transitions` | Identificar se um agente está "alucinando" demais (troca de modelo) |
| **Custo acumulado por projeto** | `agent_executions.estimated_cost_usd` | Orçamento e billing por cliente/projeto |
| **Tempo médio por task** | `tasks.started_at` → `tasks.completed_at` | Benchmark de produtividade |
| **Cache hit rate** | `agent_executions.cached` | Se não está cacheando, algo está errado na ordem do prompt |
| **Mensagens comprimidas** | `agent_conversations` + `agent_conversation_messages` | Monitorar conversas longas que podem precisar de compressão |
| **Tool calls por tipo** | `tool_calls_log.tool_name` | Identificar quais ferramentas são mais usadas e otimizar |
| **Erros do Sentinela por projeto** | `tasks WHERE source = 'sentinel'` | Identificar projetos instáveis que precisam de atenção especial |

### 9.2. Widgets Filament do Dashboard

```text
┌─────────────────────────────────────────────────────┐
│                   AI-DEV DASHBOARD                   │
│                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │ Tasks Ativas │  │ Custo Hoje   │  │ Workers    │ │
│  │     12       │  │   $2.47      │  │  5/5 OK    │ │
│  └──────────────┘  └──────────────┘  └────────────┘ │
│                                                      │
│  ┌──────────────────────────────────────────────────┐│
│  │ Kanban Board (Tasks por Status)                  ││
│  │ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────────┐ ││
│  │ │Pending │ │Running │ │QA Audit│ │  Completed  │ ││
│  │ │  █ █   │ │  █ █ █ │ │  █     │ │  █ █ █ █ █  │ ││
│  │ └────────┘ └────────┘ └────────┘ └────────────┘ ││
│  └──────────────────────────────────────────────────┘│
│                                                      │
│  ┌────────────────────┐  ┌──────────────────────────┐│
│  │ Custo por Agente   │  │  Tokens por Dia (Chart)  ││
│  │ (Gráfico de Pizza) │  │  📈 ▁▂▃▅▆▇█▇▆▅          ││
│  └────────────────────┘  └──────────────────────────┘│
│                                                      │
│  ┌──────────────────────────────────────────────────┐│
│  │ Últimos Erros do Sentinela                       ││
│  │ 🔴 QueryException em User.php:45 (há 3 min)     ││
│  │ 🟡 ViewNotFound em dashboard.blade (há 1 hora)   ││
│  │ ✅ TypeError em PostService (resolvido)           ││
│  └──────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────┘
```

### 9.3. Alertas Automáticos

| Condição | Alerta | Canal |
|---|---|---|
| Custo acumulado > $5/task | ⚠️ Task pausada por custo excessivo | Filament Notification + Log |
| Worker offline > 2 min | 🔴 Worker {nome} não responde | Filament Notification |
| Taxa de falha de agente > 50% nas últimas 10 tasks | ⚠️ Agente {nome} com alta taxa de erro | Filament Notification |
| Fila Redis > 20 jobs pendentes | 🟡 Fila congestionada | Log + Dashboard |
| API LLM retornando 429 (rate limit) consecutivamente | 🔴 Rate limit no {provider} | Failover automático + Log |

---

## 10. Fases de Implementação (MVP Incremental)

Para evitar a "síndrome do design perfeito sem código", o projeto será implementado em 3 fases incrementais. Cada fase é funcional por si só.

### Fase 1: Core Loop (MVP Mínimo Funcional)
**Objetivo:** Ter o ciclo completo rodando: Task → Orchestrator → Subagente → QA → Commit.

- [ ] Criar Migrations para: `projects`, `tasks`, `subtasks`, `agents_config`, `task_transitions`
- [ ] Criar Models + Enums com validação de transições de estado
- [ ] Implementar Agent classes: `OrchestratorAgent`, `QAAuditorAgent`, `BackendSpecialist`
  - Usar `Promptable` trait + `implements Agent, HasStructuredOutput, HasTools`
  - Configurar `config/ai.php` com provider OpenRouter (família Anthropic — Opus 4.7 / Sonnet 4.6 / Haiku 4.5)
- [ ] Implementar 6 Tools SDK: `BoostTool`, `DocSearchTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool`, `ShellExecuteTool` (`implements Laravel\Ai\Contracts\Tool`)
- [ ] Implementar `OrchestratorJob`, `ProcessSubtaskJob`, `QAAuditJob` (despachados via Laravel Queue)
- [ ] Configurar Horizon + Supervisor para as 4 filas principais
- [ ] Teste end-to-end: Criar uma task "Criar Model de Post" e ver o sistema executar sozinho

### Fase 2: Qualidade e Observabilidade
**Objetivo:** Adicionar camadas de segurança, auditoria e a interface de gestão.

- [ ] Criar Migrations para: `agent_executions`, `tool_calls_log`, `context_library`
- [ ] Implementar Filament Resources para Projects, Tasks, AgentConfig
- [ ] Implementar Dashboard com widgets de métricas (custo, saúde de workers)
- [ ] **[Alta prioridade — segurança]** Hardening do `BoostTool.database-query`: migrar de SQL raw para schema estruturado com allowlist de tabelas/colunas/operadores, redação de campos `_token`/`_secret`/`_password`/`_key`, conexão `readonly` e cap de 8 000 chars — ver `FERRAMENTAS.md §1 → Hardening do database-query`
- [ ] **[Alta prioridade — auditoria]** Implementar listener `Tool::dispatched()` em `AppServiceProvider` para popular `tool_calls_log` automaticamente em cada tool call
- [ ] **[Alta prioridade — validação]** Implementar `HasStructuredOutput` em `OrchestratorAgent`, `QAAuditorAgent` e `QuotationAgent` para que o SDK valide automaticamente o schema JSON de saída, eliminando parsing manual e falhas silenciosas de formato — ver referência: [Building Multi-Agent Workflows](https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk)
- [ ] Estender `ShellExecuteTool` para padrões seguros de teste/segurança (`php artisan test`, `php artisan enlightn`, `composer audit`, `phpstan`) com allowlist de binários
- [ ] Criar o Sentinela (Exception Handler para projetos alvo)
- [ ] Implementar Git branching por task + FileLockManager para subtasks paralelas
- [ ] Implementar circuit breakers (limites de custo, retries, tempo)

### Fase 3: Inteligência e Memória
**Objetivo:** Adicionar memória vetorial, compressão de contexto e auto-evolução.

- [ ] Criar Migration para: `problems_solutions` (com coluna `vector(1536)` via pgvector)
- [ ] Implementar `ContextCompressor` Agent (Ollama local) + `ContextCompressionJob`
- [ ] Implementar RAG vetorial via `whereVectorSimilarTo()` (pgvector nativo — sem ChromaDB)
- [ ] Implementar `toEmbeddings()` via SDK para vetorizar problemas/soluções
- [ ] Implementar `SimilaritySearch::usingModel()` como Tool nativa do SDK, registrada nos agents que fazem busca semântica — aproveita a abstração nativa em vez de reimplementar pgvector manualmente: `SimilaritySearch::usingModel(HelpArticle::class, 'embedding')->minSimilarity(0.7)` — ver referência: [Production-Safe Database Tools](https://laravel.com/blog/laravel-ai-sdk-building-production-safe-database-tools-for-agents)
- [ ] Implementar Prompt Caching (ordem correta: estático → semi-estático → dinâmico)
- [ ] Ampliar `DocSearchTool` para busca web externa (DuckDuckGo / Firecrawl self-hosted) como fallback quando Boost não tiver a doc localmente
- [ ] Implementar `ProblemSolutionRecorder` (auto-alimentação via Listener)
- [ ] Implementar webhooks de entrada (GitHub, CI/CD)
- [ ] Implementar `SocialPostingAgent` (publicação via `hamzahassanm/laravel-social-auto-post`) + migration `social_accounts` + Filament Resource

---

## 11. Integração com Redes Sociais — *Fase 3 (planejado)*

> **Status:** Não implementado. Migration `social_accounts` existe; não há Tool nem Agent. Esta seção descreve o design-alvo.

A publicação em redes sociais usará o pacote **`hamzahassanm/laravel-social-auto-post`** (v2.2+) encapsulado em um **Agent dedicado** (`SocialPostingAgent`), não em uma Tool SDK genérica. Razão: publicação envolve decisão editorial (tom, plataforma, timing) que é responsabilidade de um agente, e o SDK de publicação já fornece a API — uma Tool seria um wrapper fino sem valor.

### 11.1. Plataformas Suportadas

| Plataforma | Tipos de Conteúdo | Recurso Extra |
|---|---|---|
| **Facebook** | Texto, imagens, vídeos, stories | Pages, Grupos |
| **Instagram** | Fotos, Reels, Stories, Carrossel | Business API |
| **Twitter/X** | Tweets, imagens, vídeos | Thread support |
| **LinkedIn** | Posts, artigos, vídeos | Company pages |
| **TikTok** | Vídeos curtos | Creator API |
| **YouTube** | Upload de vídeos | Playlists, descrição |
| **Pinterest** | Pins com imagens | Boards |
| **Telegram** | Mensagens, arquivos, fotos | Channels, Bots |

### 11.2. Instalação

```bash
composer require hamzahassanm/laravel-social-auto-post:^2.2
php artisan vendor:publish --provider="HamzaHassanM\LaravelSocialAutoPost\SocialAutoPostServiceProvider"
```

**Variáveis de ambiente necessárias por plataforma** (adicionadas ao `.env` do projeto):
```env
# Facebook
FACEBOOK_PAGE_ACCESS_TOKEN=
FACEBOOK_PAGE_ID=

# Instagram (via Facebook Graph API)
INSTAGRAM_ACCESS_TOKEN=
INSTAGRAM_ACCOUNT_ID=

# Twitter/X
TWITTER_API_KEY=
TWITTER_API_SECRET=
TWITTER_ACCESS_TOKEN=
TWITTER_ACCESS_TOKEN_SECRET=

# LinkedIn
LINKEDIN_ACCESS_TOKEN=
LINKEDIN_COMPANY_ID=

# TikTok
TIKTOK_ACCESS_TOKEN=

# YouTube
YOUTUBE_API_KEY=
YOUTUBE_CHANNEL_ID=

# Pinterest
PINTEREST_ACCESS_TOKEN=
PINTEREST_BOARD_ID=

# Telegram
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHANNEL_ID=
```

### 11.3. Design-Alvo: `SocialPostingAgent`

```php
// app/Ai/Agents/SocialPostingAgent.php (planejado — não implementado)
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Attributes\{Provider, Model};

#[Provider('openrouter')]
#[Model('anthropic/claude-haiku-4-5-20251001')]
class SocialPostingAgent implements Agent
{
    use Promptable;

    public function __construct(public Project $project) {}

    public function instructions(): string
    {
        return 'Você redige posts curtos para redes sociais a partir de um evento do projeto (deploy, lançamento, relatório). Use o pacote hamzahassanm/laravel-social-auto-post via SocialMedia::share()/shareToAll() com as credenciais de social_accounts do projeto ativo.';
    }
}
```

A orquestração decide *quando* chamar este agente; o pacote externo cuida do *como*. Não há camada Tool intermediária.

### 11.4. Gatilhos Previstos

1. **Deploy concluído** — `DevOpsSpecialist` dispara `SocialPostingAgent` para anunciar no LinkedIn/Twitter.
2. **Feature aprovada** — Ao aprovar uma subtask de release, o `QAAuditorAgent` dispara um post de lançamento.
3. **Relatório semanal** — Task agendada gera e publica relatório de progresso.
4. **Notificação de manutenção** — Aviso via Telegram antes de janelas programadas.

### 11.5. Gerenciamento via Filament UI

O `SocialAccountResource` no Filament está **implementado** e já permite:
- Cadastrar e editar credenciais por plataforma por projeto
- Visualizar contas registradas por projeto
- Ativar/desativar plataformas

Pendente (quando o `SocialPostingAgent` for implementado):
- Visualizar histórico de publicações (tabela `social_posts_log`)
- Preview e agendamento de posts automatizados

---

## 13. Referências e Abstração de Conhecimento (Third-World Evolution)

Para acelerar o desenvolvimento e garantir que o AI-Dev (AndradeItalo.ai) opere no estado da arte, abstrairemos conceitos, lógicas de paralelismo e ferramentas dos seguintes repositórios de código aberto:

*   **OpenClaude (`https://github.com/Gitlawb/openclaude`)**:
    *   *Foco da Extração:* Como gerir de forma eficiente a injeção do Model Context Protocol (MCP) para uso de ferramentas do sistema (Ler/Escrever Arquivos, Rodar Comandos) pelo LLM.
    *   *Foco da Extração:* A lógica abstrata de "routing" no JSON de configuração para selecionar diferentes provedores (Anthropic, OpenAI, Google) dinamicamente.
*   **OpenClaw (`https://github.com/openclaw/openclaw`)**:
    *   *Foco da Extração:* A arquitetura subjacente de delegação multi-agente assíncrona.
    *   *Foco da Extração:* Lógicas de gerenciamento do ciclo de vida das *Tasks* em sistemas headless (daemon/workers) orientados a banco de dados.
*   **Hermes Agent (`https://github.com/NousResearch/hermes-agent`)**:
    *   *Foco da Extração:* O conceito de Action-Driven Heartbeat (abandono do timer vazio) e a preferência pelo uso de Bancos de Dados SQLite/Relacionais para memória com **Compressão Ativa** em vez de arquivos Markdown infinitos. 
    *   *Foco da Extração:* Filosofia inteligente de web scraping usando APIs dedicadas (como o Firecrawl) para retornar puro Markdown em vez de sobrecarregar a LLM com ações visuais pesadas no DOM.

**A Missão do Terceiro Mundo (The Best of Both Worlds):** O AI-Dev não é um fork direto. Ele atua como uma evolução que pega as ideias dispersas de CLI/Local de ambos os repositórios, mescla isso com a rigidez do controle via Tabela de Banco de Dados Relacional, e padroniza tudo *exclusivamente* para o ecossistema TALL + Filament + Anime.js, elevando a abstração ao máximo.

---

## 14. Diretrizes Obrigatórias para Módulos Desenvolvidos

### 12.1. PRD Obrigatório por Módulo

Todo módulo, feature ou componente desenvolvido pelo AI-Dev (ou manualmente) **DEVE** possuir um PRD (Product Requirement Document) em três locais simultâneos:

1. **Arquivo `.md` no próprio diretório do módulo:**
   - Nome: `PRD.md` na raiz do diretório do módulo (ex: `app/Modules/Auth/PRD.md`)
   - Conteúdo: Descrição completa do que o módulo faz, por que existe, critérios de aceite, dependências e histórico de decisões
   - Mantido atualizado a cada alteração significativa

2. **Banco de dados de desenvolvimento (`tasks.prd_payload`):**
   - O PRD em formato JSON estruturado, conforme `PRD_SCHEMA.md`
   - Vinculado à task que originou o módulo
   - Permite busca, auditoria e rastreabilidade via Filament UI

3. **Documentação centralizada do sistema:**
   - Referência no `ARCHITECTURE.md` ou em documento de índice que liste todos os módulos com links para seus PRDs
   - Permite visão panorâmica do sistema sem navegar pelos diretórios

**Por que três locais?** O arquivo `.md` no diretório serve para o desenvolvedor que está no código. O banco de dados serve para o sistema autônomo (agentes e Filament UI). A documentação central serve para visão estratégica e onboarding.

### 12.2. WebMCP (Web Model Context Protocol) — Padrão Obrigatório

Todo sistema web desenvolvido pelo AI-Dev **DEVE** implementar o padrão **WebMCP** (Web Model Context Protocol), criado pelo Google em conjunto com a Microsoft como proposta de padrão W3C.

**O que é o WebMCP:**
O WebMCP introduz a API `navigator.modelContext` diretamente no navegador (implementado experimentalmente no Chrome Canary), criando uma linguagem nativa e direta entre agentes de IA e aplicações web.

**Como funciona:**

| Sem WebMCP (legado) | Com WebMCP |
|---|---|
| Agente faz leitura visual (screenshots) ou parseia o DOM/HTML | Site expõe ferramentas estruturadas via `navigator.modelContext` |
| Lento, alto gasto de tokens, quebra se layout muda | Eficiente, tipado, contrato estável entre IA e aplicação |
| Adivinhação de onde clicar e o que preencher | Declaração proativa: "esta página tem estas funções chamáveis" |

**Implementação obrigatória em cada página/componente:**

```javascript
// Exemplo: Página de produto expõe suas ações via WebMCP
if ('modelContext' in navigator) {
  navigator.modelContext.registerTools([
    {
      name: 'addToCart',
      description: 'Adiciona o produto ao carrinho de compras',
      parameters: {
        productId: { type: 'string', required: true },
        quantity: { type: 'integer', default: 1 }
      },
      handler: async ({ productId, quantity }) => {
        return await addToCart(productId, quantity);
      }
    },
    {
      name: 'searchProducts',
      description: 'Busca produtos por termo',
      parameters: {
        query: { type: 'string', required: true },
        category: { type: 'string' }
      },
      handler: async ({ query, category }) => {
        return await searchProducts(query, category);
      }
    }
  ]);
}
```

**Regras:**
- Toda página com interações significativas (formulários, CRUDs, buscas, ações) DEVE registrar suas ferramentas via `navigator.modelContext.registerTools()`
- Os nomes das ferramentas devem ser descritivos e em camelCase
- Os parâmetros devem ter `type` e `description` explícitos
- O `handler` deve retornar JSON estruturado com o resultado da ação
- Componentes Livewire devem expor seus métodos públicos como ferramentas WebMCP
- O registro deve ser feito no `x-init` do Alpine.js ou no `mount()` do Livewire

**Benefício direto para o AI-Dev:** Quando o Sentinela ou um agente precisar interagir com a UI de um projeto desenvolvido (para teste funcional, por exemplo), ele poderá usar o WebMCP em vez de Dusk/screenshot, economizando tokens e eliminando flakiness.

---

## 15. Rastreabilidade de Commits e Rollback por Task

### 13.1. Commit Hash por Subtask e Task

O sistema salva o hash do commit Git (`commit_hash`) em dois níveis:

- **`subtasks.commit_hash`**: Hash do commit gerado quando o QA Auditor aprova a subtask. Cada subtask aprovada = 1 commit atômico.
- **`tasks.commit_hash`**: Hash do último commit da task (após todas as subtasks serem aprovadas). Representa o "ponto de restauração" completo.

**Fluxo:**
```text
Subtask aprovada → git add -A → git commit "ai-dev: {título} [subtask:{id}]" → salva hash
Todas subtasks OK → task.commit_hash = último hash → task = completed
```

**Rollback:**
Para desfazer uma task inteira: `git revert <task.commit_hash>` ou, para rollback granular de uma subtask específica: `git revert <subtask.commit_hash>`.

### 13.2. Redo de Tasks (Re-execução em vez de Nova Task)

Quando uma task foi executada de forma incorreta ou precisa de ajustes, o sistema permite **refazer a mesma task** (redo) em vez de criar uma nova. Isso:
- Mantém a rastreabilidade (a chain `original_task_id → redo → redo`)
- Preserva o histórico de tentativas e feedbacks do QA
- Evita duplicação de PRDs e poluição no banco de dados

**Colunas envolvidas:**
- `tasks.is_redo` (boolean): Indica se a task é um redo
- `tasks.original_task_id` (FK → tasks.id): Aponta para a task original

**Uso via código:**
```php
$task = Task::find($uuid);
$redo = $task->redo(); // Cria redo linkado, status pending
// Ou com PRD atualizado:
$redo = $task->redo(updatedPrd: $novoPrd);
OrchestratorJob::dispatch($redo);
```

---

## 16. Invocação dos LLMs via OpenRouter

### 16.1. Princípio Fundamental: IA Retorna Texto, AI-Dev Executa

Os LLMs **não executam nada diretamente** no servidor. Eles recebem um prompt e retornam texto ou tool calls estruturadas. Toda execução real — shell, filesystem, banco, git — passa pelas **Tool classes** do AI-Dev (`ShellExecuteTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool`, `BoostTool`, `DocSearchTool`), que têm seus próprios controles internos e operam dentro do `projects.local_path` do Projeto Alvo (ver seção 1.A.1).

### 16.2. OpenRouter como Gateway Único

Toda inferência externa passa por **um único endpoint HTTPS**: OpenRouter (`https://openrouter.ai/api/v1`). O Laravel AI SDK usa o driver `openai` (compatível com a API OpenAI) para conversar com esse endpoint — não há proxies Python locais, não há CLIs invocados, não há sessões de terminal.

```text
Laravel AI SDK (config/ai.php: provider 'openrouter')
      ↓  HTTPS POST /v1/chat/completions
OpenRouter
      ↓  roteia para o backend da Anthropic
anthropic/claude-opus-4.7       → Orchestrator, Specification, Quotation, RefineDescription
anthropic/claude-sonnet-4-6     → Specialist, QAAuditor
anthropic/claude-haiku-4-5-20251001 → Docs
```

Configuração em `config/ai.php` (resumo — chaves `driver`/`key`/`url` conforme `docs_tecnicos/laravel13-docs/ai-sdk.md` §Custom Base URLs):

```php
'openrouter' => [
    'driver' => 'openai',
    'key'    => env('OPENROUTER_API_KEY'),
    'url'    => 'https://openrouter.ai/api/v1',
],

// Chain com fallback local, registrada via FailoverProvider:
'openrouter_chain' => [
    'driver'    => 'failover',
    'providers' => ['openrouter', 'openai'],
],
```

### 16.3. Ollama Local (Fase 3 — Compressor)

Além do OpenRouter, existe **um** canal local: Ollama, servido em `localhost:11434`, usado exclusivamente pelo `ContextCompressor` com `qwen2.5:0.5b`. Não participa do fluxo principal de geração de código — só comprime histórico longo quando a janela atinge 60% e gera embeddings quando pgvector é consultado.

```text
ContextCompressor → Ollama (qwen2.5:0.5b) → resumo denso ~500-1000 tokens → agent_conversations
```

### 16.4. Estratégia de Failover Dentro do OpenRouter

O Laravel AI SDK dispara o evento `AgentFailedOver` quando o provider primário falha. O SDK expõe três formas de declarar failover (ver assinaturas em `vendor/laravel/ai/src/Attributes/Provider.php` e `Model.php`):

1. **Atributo `#[Provider(...)]` com array** — válido (assinatura `Lab|array|string`). Fixa a chain a nível de classe.
2. **Runtime `->prompt(..., provider: [...])`** — recomendado pela doc oficial `ai-sdk.md §Failover`. Mais flexível; permite variar a chain por chamada.
3. **Alias `openrouter_chain`** (driver `failover`) — encapsula a chain atrás de um único nome; o agente passa apenas `'openrouter_chain'` e ignora os detalhes.

O atributo `#[Model(...)]` **aceita apenas string** — nunca array. Para rotear entre modelos diferentes use o parâmetro `model:` do `prompt()` em runtime.

```php
// ❌ Errado — #[Model] não aceita array (assinatura: public string $value)
#[Provider('openrouter')]
#[Model(['anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4-6'])]

// ✅ Correto (forma 2) — array de providers em runtime
$response = (new OrchestratorAgent)->prompt(
    $task->prd_payload,
    provider: ['openrouter', 'openrouter_chain'],
);
```

Alternativamente, use o provider alias `openrouter_chain` (driver `failover` — ver `app/Ai/Providers/FailoverProvider.php`), que encadeia `openrouter → openai` de forma transparente sem o agente precisar saber:

```php
$response = (new OrchestratorAgent)->prompt($prompt, provider: 'openrouter_chain');
```

A tabela `agent_executions` registra qual provider/model foi efetivamente usado em cada chamada para auditoria.

Caso **todos** os providers da chain estejam fora do ar, a task vai para `escalated` e notifica humano — o SDK repassa a exceção após esgotar a lista.

### 16.5. Session ID por Projeto

O conversation ID da sessão SDK é armazenado na tabela `projects` na coluna `anthropic_session_id` (coluna única — não há mais `gemini_session_id` / `claude_session_id` separadas do modelo antigo de Inferência Dupla).

O trait `RemembersConversations` do SDK gerencia a continuidade:

```php
$agent = BackendSpecialist::make();

// Nova conversa (1ª chamada para o projeto)
$response = $agent->forUser($systemUser)->prompt($prompt);
$project->update(['anthropic_session_id' => $response->conversationId]);

// Continuar conversa (chamadas subsequentes)
$response = $agent->continue($project->anthropic_session_id, as: $systemUser)->prompt($prompt);
```

Isso garante que:
1. A IA mantém contexto entre chamadas (memória da conversa persistida em `agent_conversations`/`agent_conversation_messages` no banco do ai-dev-core).
2. Cada projeto tem seu próprio contexto isolado.
3. O contexto da IA complementa a memória vetorial do AI-Dev (dupla camada de memória).

---

## 17. Laravel Boost + MCP: Padrão Obrigatório de Desenvolvimento

### 17.1. O que é o Boost e por que elimina desperdício de tokens

O **Laravel Boost** (`laravel/boost`) é um servidor MCP nativo do Laravel que já tem **toda a documentação do stack TALL mapeada e integrada** — Filament v5, Livewire 4, Alpine.js v3, Laravel 13, Tailwind v4.

O ponto central: **o agente não precisa conhecer o framework**. Ele não carrega a documentação no contexto, não tenta adivinhar a API, não acumula exemplos em memória. Quando precisa implementar algo, envia uma ação ao Boost via MCP e recebe de volta o código pronto, correto e alinhado com a versão exata do stack instalado.

```
Agente recebe Sub-PRD
      ↓
Envia ação ao Boost via MCP: "criar Resource Filament v5 para entidade X"
      ↓
Boost retorna: código completo + padrões obrigatórios + exemplos do projeto
      ↓
Agente implementa o que recebeu — sem improvisação, sem alucinação de API
```

**Impacto direto:** Menos retries, menos tokens por task, menos rejeições do QA Auditor. O Boost age como um copiloto que sempre conhece a versão certa do framework — algo que o LLM, por si só, não tem garantia de ter.

### 17.2. Dois Contextos de Uso — Boost Instalado dos Dois Lados

O Boost é instalado **em cada aplicação Laravel do ecossistema** (ai-dev-core e cada Projeto Alvo). A diferença não é *se* ele existe, mas **quem o consome**:

| Boost instalado em… | Consumidor | Propósito |
|---|---|---|
| `ai-dev-core` | Claude Code (humano) via `.claude/mcp.json` local | Desenvolvimento/manutenção do próprio ai-dev-core |
| Cada Projeto Alvo (`projects.local_path`) | Agentes do ai-dev-core, via `BoostTool` | Fonte de contexto para gerar e auditar código do alvo — schema, docs, logs |

#### Agentes Autônomos (Fluxo AI-Dev) → Boost do Projeto Alvo

O `SpecialistAgent` não precisa de contexto sobre Filament ou Livewire no seu prompt. O `BoostTool` é instanciado com o `local_path` do Projeto Alvo e roteia `php artisan boost:execute-tool` **dentro** daquele path. Assim, o agente recebe schema, docs e exemplos referentes à versão exata instalada **no alvo**, não no ai-dev-core:

```
❌ Sem Boost — agente carrega docs no contexto:
"Você é um especialista em Filament v5. A API de Forms usa Schema $schema...
 A API de Tables usa Table $table... O infolist usa RepeatableEntry... [+2000 tokens de doc]
 Agora crie um Resource para Produto."
→ Contexto inflado, API possivelmente desatualizada, mais erros

✅ Com Boost MCP do Projeto Alvo — agente só recebe o problema:
"Crie um Resource Filament para a entidade Produto com campos: nome, preço, estoque."
→ BoostTool(projectPath=/var/www/html/projetos/portal) → executa boost:execute-tool dentro do alvo
→ Retorna scaffold na versão real instalada do alvo → agente implementa no filesystem do alvo
→ Contexto limpo, zero risco de sugerir API de uma versão que o alvo não tem
```

O `DocSearchTool` delega ao `DocsAgent` (Haiku 4.5), que usa o mesmo `BoostTool` escopado ao `local_path` do alvo — logo, a documentação retornada reflete as versões TALL instaladas naquele projeto específico.

> **Implementation status — BoostTool project-path-aware:** Implementado. `BoostTool` recebe `project.local_path`, chama `boost:execute-tool` no Projeto Alvo e valida `database-query` contra o schema real retornado por aquele Boost.

#### Desenvolvimento Manual com Claude Code → Boost do próprio projeto que está sendo editado

Quando um humano edita código manualmente (seja no ai-dev-core via Claude Code, seja em um Projeto Alvo durante uma inspeção), o Claude Code dessa janela deve estar conectado ao Boost **daquela** aplicação. A configuração MCP vive no `.claude/mcp.json` do próprio repositório editado:

```json
// ai-dev-core: /var/www/html/projetos/ai-dev/ai-dev-core/.claude/mcp.json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "php",
      "args": ["artisan", "mcp:serve"],
      "cwd": "/var/www/html/projetos/ai-dev/ai-dev-core"
    }
  }
}

// Projeto Alvo qualquer: /var/www/html/projetos/<alvo>/.claude/mcp.json
{
  "mcpServers": {
    "laravel-boost": {
      "command": "php",
      "args": ["artisan", "mcp:serve"],
      "cwd": "/var/www/html/projetos/<alvo>"
    }
  }
}
```

Cada janela de Claude Code consulta apenas o Boost do projeto em que está — sem mistura de contexto entre ai-dev-core e Projetos Alvo.

### 17.3. O Boost Resolve, Não Só Documenta

Esta é a distinção crítica: o Boost não é uma biblioteca de referência que o agente lê. É um **resolvedor ativo**. Quando recebe uma ação, retorna a implementação completa. O agente não interpreta docs — executa o que o Boost entregou.

| Capacidade | Comportamento |
|---|---|
| **Gerar scaffold** | Recebe "Resource para Produto" → retorna arquivo PHP completo e correto |
| **Aplicar guidelines do projeto** | Já inclui as regras definidas (padrões do AI-Dev) no código gerado |
| **Versão correta** | Usa a API da versão instalada (`composer.json`), não de versões antigas |
| **Consistência entre tasks** | Task 1 e Task 20 geram código no mesmo padrão — sem divergência de estilo |
| **Zero contexto de framework no agente** | O prompt do agente fala de negócio, não de sintaxe do framework |

### 17.4. Registrar Padrões do Projeto como Guidelines no Boost

Guidelines são registradas **no Boost do projeto a que pertencem** — o ai-dev-core tem suas próprias guidelines (padrões internos do Master), e cada Projeto Alvo tem as suas (padrões do alvo consumidos pelos agentes de dev via BoostTool). O exemplo abaixo é o do ai-dev-core.

Todo padrão adotado no AI-Dev **DEVE** ser registrado no Boost como Guideline — não apenas documentado em Markdown. Isso garante que o Boost injete as regras do projeto automaticamente em todo código que gerar, sem que o agente precise conhecê-las:

```php
// app/Boost/Guidelines/AiDevFilamentGuideline.php
use Laravel\Boost\Contracts\Guideline;

class AiDevFilamentGuideline implements Guideline
{
    public function title(): string
    {
        return 'AI-Dev: Padrões Filament v5';
    }

    public function content(): string
    {
        return <<<'MD'
        - form() e infolist() recebem `Schema $schema` — nunca `Form $form`
        - Infolists usam `Group::make()` com `columnSpan` para layout 2 colunas
        - ViewRecord pages: EditAction primeiro, depois ações de negócio customizadas
        - Toda TextEntry com dado nullable DEVE ter `->placeholder('—')`
        - Resources usam UUID: `HasUuids` no Model, `foreignUuid()` nas migrations
        - Nunca usar `->bulkActions([])` sem array vazio explícito
        MD;
    }
}
```

Quando o Boost gera um Resource qualquer para o AI-Dev, ele **automaticamente** aplica estas regras — o agente não precisa conhecê-las.

### 17.5. Fluxo de Implementação de uma Task com Boost

```
Task recebida pelo Specialist Agent
      ↓
1. Lê o Sub-PRD (objetivo, entidade, campos, critérios de aceite)
      ↓
2. Chama `BoostTool` (`tool=search-docs`) pedindo "scaffold Resource Filament para [entidade] com campos [X,Y,Z]"
      ↓
3. Boost retorna: Resource.php + Pages/ + Migration + Model (scaffolds completos via search-docs)
      ↓
4. Agente ajusta apenas a lógica de negócio específica (regras do Sub-PRD)
      ↓
5. Roda `ShellExecuteTool` (`php artisan test`) → `QAAuditorAgent` valida → `GitOperationTool` commit
```

O agente gasta tokens **apenas nas partes únicas** do Sub-PRD — a lógica de negócio específica. O framework boilerplate vem pronto do Boost.

> **Estado atual:** O sistema de gerenciamento (este Filament app) já está operacional. Todo desenvolvimento futuro — autônomo ou manual — deve seguir este fluxo. Conecte o Claude Code ao Boost MCP antes de qualquer sessão de desenvolvimento neste projeto.
