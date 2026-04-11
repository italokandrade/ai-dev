# Arquitetura do AI-Dev (AndradeItalo.ai)

## 1. Visão Geral da Arquitetura

O AI-Dev é um ecossistema de desenvolvimento de software autônomo, assíncrono e multi-agente, estritamente focado na stack TALL (Tailwind, Alpine.js, Laravel, Livewire) + Filament v5 + Anime.js. Ele opera em background (headless), guiado por um banco de dados relacional PostgreSQL e enriquecido por uma memória de longo prazo vetorial nativa (pgvector). As instruções trafegam em formato PRD (Product Requirement Document) para garantir clareza absoluta na comunicação entre os agentes.

**Componentes Fundamentais do Ecossistema:**

```text
┌──────────────────────────────────────────────────────────────────────┐
│                        AI-DEV CORE (Laravel 13)                      │
│                                                                      │
│  ┌────────────┐   ┌──────────────┐   ┌───────────────────────────┐  │
│  │ Filament v5 │   │  Prompt       │   │   Tool Layer (MCP)        │  │
│  │ (Web UI)    │   │  Factory      │   │   (Plugins Isolados)      │  │
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
│  │   Motores LLM (Inferência Dupla)                             │    │
│  │   Gemini Flash (Executor) │ Claude Opus/Sonnet (Cérebro)     │    │
│  │   Ollama Local (Compressor)                                   │    │
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

---

## 2. Modelagem do Banco de Dados Relacional (Core), Web UI e API Headless

Diferente da versão inicial puramente CLI, o AI-Dev contará com uma **Interface Web (UI)** desenvolvida em Filament v5 e uma **API Headless** (via gRPC ou REST). 
- **Web UI:** Servirá *exclusivamente* para gestão: cadastrar novos projetos, configurar o prompt dos agentes, inserir tarefas/PRDs manualmente, e monitorar o progresso em tempo real via dashboard.
- **API Headless:** Permitirá que sistemas externos (como webhooks do GitHub, pipelines de CI/CD ou extensões de VS Code) injetem tarefas e ouçam o progresso em tempo real.

O Orquestrador continua operando em background via *polling/events* nestas tabelas.

### 2.1. Tabelas Principais (Esquema Completo)

**`projects`** — Cadastro de cada sistema/aplicação gerenciado pelo AI-Dev.
Cada projeto é um site/app Laravel distinto (ex: `italoandrade.com`, `meuapp.com.br`).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único do projeto |
| `name` | String(255) | Nome legível do projeto (ex: "Portal ItaloAndrade") |
| `github_repo` | String(255) | URL do repositório GitHub (ex: `italokandrade/portal`) |
| `local_path` | String(500) | Caminho absoluto no servidor (ex: `/var/www/html/projetos/portal`) |
| `gemini_session_id` | String / Nullable | UUID da conversa persistida no Proxy Gemini — permite contexto infinito por projeto |
| `claude_session_id` | String / Nullable | UUID da conversa persistida na Anthropic — idem |
| `default_provider` | Enum: `gemini`, `claude`, `ollama` | Qual motor de IA usar por padrão para este projeto |
| `default_model` | String(100) | Modelo padrão (ex: `gemini-3.1-flash-lite-preview`, `claude-sonnet-4-6`) |
| `status` | Enum: `active`, `paused`, `archived` | Status operacional. `paused` = aceita tasks mas não processa |
| `created_at` | Timestamp | Data de criação |
| `updated_at` | Timestamp | Última modificação |

**Por que `default_provider` e `default_model` na tabela `projects`?** Porque projetos diferentes podem ter necessidades diferentes. Um projeto simples pode usar apenas Gemini Flash (barato e rápido), enquanto um projeto crítico pode exigir Claude Opus para planejamento mais cuidadoso. Isso é configurável por projeto, sem mexer em código.

---

**`tasks`** — Cada tarefa de desenvolvimento solicitada (via UI, API ou Sentinela).
Uma task é sempre acompanhada de um PRD completo (ver `PRD_SCHEMA.md`).

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único da tarefa |
| `project_id` | FK → `projects.id` | A qual projeto pertence |
| `title` | String(500) | Título legível da tarefa (ex: "Criar Resource de Usuários") |
| `prd_payload` | JSON | O PRD completo em formato JSON estruturado (ver `PRD_SCHEMA.md`) |
| `status` | Enum (ver abaixo) | Estado atual na máquina de estados |
| `priority` | Int (1-100) | Prioridade de execução. 100 = máxima (reservado para Sentinela) |
| `assigned_agent_id` | FK → `agents_config.id` / Nullable | Qual agente está responsável (preenchido pelo Orchestrator) |
| `git_branch` | String(100) / Nullable | Nome do branch Git criado para isolar esta task (ex: `task/a1b2c3d4`) |
| `commit_hash` | String(40) / Nullable | Hash do commit final da task (último commit após todas subtasks aprovadas). Permite rollback completo |
| `last_session_id` | String / Nullable | ID da conversa LLM usada nesta tarefa para manter contexto |
| `retry_count` | Int (default: 0) | Quantas vezes esta task já foi re-executada após falha |
| `max_retries` | Int (default: 3) | Limite de retentativas antes de escalar para Human-in-the-Loop |
| `error_log` | Text / Nullable | Último erro registrado (stack trace, mensagem de falha) |
| `source` | Enum: `manual`, `webhook`, `sentinel`, `ci_cd` | De onde esta task veio (UI? Sentinela? GitHub webhook?) |
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
| `provider` | String(50) | Provedor de IA (ex: `gemini`, `anthropic`, `ollama`) |
| `model` | String(100) | Modelo específico (ex: `gemini-3.1-flash-lite-preview`, `claude-sonnet-4-6`) |
| `api_key_env_var` | String(100) | Nome da variável de ambiente com a chave API (ex: `GEMINI_API_KEY`) |
| `temperature` | Float (0.0 - 2.0) | Criatividade (0.0 = determinístico, 1.0+ = criativo). Orchestrator usa 0.2, Executores usam 0.4 |
| `max_tokens` | Int | Máximo de tokens de saída por resposta. Padrão: 8192 |
| `knowledge_areas` | JSON | Array de áreas de conhecimento do agente (ex: `["backend", "database", "filament"]`) |
| `max_parallel_tasks` | Int (default: 1) | Quantas subtasks este agente pode processar simultaneamente |
| `is_active` | Boolean (default: true) | Se o agente está disponível para receber tarefas |
| `fallback_agent_id` | String / Nullable / FK → `agents_config.id` | Agente substituto se este falhar (redundância) |

**Estratégia de Providers de IA (Decisão de Arquitetura):**

O AI-Dev usa **dois proxies de IA** com papéis invertidos por classe de agente:

| Classe | Provider Principal | Provider Backup | Motivo |
|---|---|---|---|
| **Orchestrator** | **Gemini** (via proxy) | Claude (fallback) | Gemini tem maior cota de uso gratuito; o Orchestrator é chamado muito frequentemente |
| **Todos os Agentes Specialists** | **Claude** (via proxy) | Gemini (fallback) | Claude produz código mais preciso e com menos alucinações nos especialistas |
| **QA Auditor** | **Claude** (via proxy) | Gemini (fallback) | Auditoria exige raciocínio rigoroso — Claude é superior aqui |
| **Context Compressor** | **Ollama** (local) | — | Sem custo de API; modelo leve suficiente para sumarização |

**SDK Default (`config/ai.php`):** `openai` com modelo `gpt-5-nano` — usado como fallback geral e para tasks onde nenhum provider específico foi configurado.

**Agentes Padrão Pré-Configurados:**

| ID | Papel | Provider Principal | Backup | Temperatura |
|---|---|---|---|---|
| `orchestrator` | Planner — Recebe o PRD e quebra em Sub-PRDs | `gemini` (via proxy) | `anthropic` | 0.2 |
| `qa-auditor` | Judge — Audita cada entrega contra o PRD | `anthropic` (via proxy) | `gemini` | 0.1 |
| `security-specialist` | Auditor — Pentest, OWASP Top 10, vulnerabilidades | `anthropic` (via proxy) | `gemini` | 0.1 |
| `performance-analyst` | Analista — N+1 queries, slow queries, otimizações | `anthropic` (via proxy) | `gemini` | 0.2 |
| `backend-specialist` | Executor — Controllers, Models, Services, Migrations | `anthropic` (via proxy) | `gemini` | 0.4 |
| `frontend-specialist` | Executor — Blade, Livewire, Alpine.js, Tailwind, Anime.js | `anthropic` (via proxy) | `gemini` | 0.5 |
| `filament-specialist` | Executor — Resources, Pages, Widgets, Forms, Tables Filament v5 | `anthropic` (via proxy) | `gemini` | 0.3 |
| `database-specialist` | Executor — Migrations, Seeders, Queries complexas | `anthropic` (via proxy) | `gemini` | 0.2 |
| `devops-specialist` | Executor — CI/CD, deploy, permissões, Supervisor | `anthropic` (via proxy) | `gemini` | 0.2 |
| `context-compressor` | Utilitário — Comprime sessões longas em resumos | `ollama` (qwen2.5:0.5b) | — | 0.1 |

---

**`context_library`** — Padrões Estritos de código (a "Bíblia TALL").
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

**`agent_executions`** — Log detalhado de cada chamada LLM feita por qualquer agente.
Essencial para controle de custo, debugging e otimização.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `agent_id` | FK → `agents_config.id` | Qual agente fez a chamada |
| `subtask_id` | FK → `subtasks.id` / Nullable | Subtask associada (se aplicável) |
| `task_id` | FK → `tasks.id` / Nullable | Task associada |
| `provider` | String(50) | Provedor usado nesta chamada (ex: `gemini`, `anthropic`) |
| `model` | String(100) | Modelo usado (ex: `gemini-3.1-flash-lite-preview`) |
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

**`tool_calls_log`** — Registro de cada ferramenta executada pelos agentes.
A camada de segurança e auditoria — permite investigar exatamente quais comandos foram rodados, quais arquivos foram alterados, e por quem.

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | UUID / PK | Identificador único |
| `agent_execution_id` | FK → `agent_executions.id` | A qual chamada LLM esta tool call pertence |
| `subtask_id` | FK → `subtasks.id` / Nullable | Subtask associada |
| `tool_name` | String(50) | Nome da ferramenta (ex: `ShellTool`, `FileTool`) |
| `tool_action` | String(50) | Ação específica (ex: `execute`, `write`, `read`, `search`) |
| `input_params` | JSON | Parâmetros de entrada enviados pelo agente |
| `output_result` | Text / Nullable | Resultado retornado pela ferramenta |
| `status` | Enum: `success`, `error`, `blocked`, `timeout` | Resultado da execução |
| `execution_time_ms` | Int | Tempo de execução em milissegundos |
| `security_flag` | Boolean (default: false) | Se o filtro de segurança detectou algo suspeito |
| `created_at` | Timestamp | Quando foi executada |

---

**`problems_solutions`** — Base de conhecimento auto-alimentada.
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

**`social_accounts`** — Credenciais de redes sociais vinculadas a cada projeto.
Cada projeto pode publicar em múltiplas redes sociais via o pacote `hamzahassanm/laravel-social-auto-post`. As credenciais são armazenadas aqui, criptografadas, e injetadas pelo `SocialTool` em runtime.

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

**`webhooks_config`** — Configuração de webhooks de entrada para integração com GitHub, CI/CD, etc.

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
projects ──┬── 1:N ── tasks ──┬── 1:N ── subtasks ──── N:1 ── agents_config
            │                   │                                    │
            │                   ├── 1:N ── task_transitions          │
            │                   │                                    │
            │                   └── 1:N ── agent_executions ─── 1:N ── tool_calls_log
            │
            ├── 1:N ── problems_solutions
            ├── 1:N ── agent_conversations (SDK — RemembersConversations)
            ├── 1:N ── social_accounts
            └── 1:N ── webhooks_config

context_library (standalone — padrões globais, não vinculados a projeto)
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
                    │ 5. Despacha SubagentJobs     │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │  Redis Queue: "executors"     │
                    │                               │
                    │ ┌──────────────────────────┐  │
                    │ │ SubagentJob(subtask_id=1) │  │ ◀── Backend Specialist
                    │ │ SubagentJob(subtask_id=2) │  │ ◀── Frontend Specialist
                    │ │ SubagentJob(subtask_id=3) │  │ ◀── Filament Specialist
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
| `queue:orchestrator` | Orchestrator | 1 | Apenas 1 worker — o planejador é sequencial (não pode planejar 2 tasks ao mesmo tempo) |
| `queue:executors` | Subagentes | 3 | Até 3 subagentes executando em paralelo (configurável via Horizon) |
| `queue:qa-auditor` | QA Auditor | 1 | Apenas 1 worker — a auditoria é sequencial |
| `queue:security` | Security Specialist | 1 | Apenas 1 worker — auditoria de segurança pós-QA |
| `queue:performance` | Performance Analyst | 1 | Apenas 1 worker — análise de performance pós-QA |
| `queue:compressor` | Context Compressor | 1 | Apenas 1 worker — compressão de contexto em background |
| `queue:sentinel` | Sentinel Watcher | 1 | Apenas 1 worker — processa erros runtime |

**Por que 1 worker para o Orchestrator?** Porque se dois OrchestratorJobs rodarem ao mesmo tempo, ambos podem pegar a MESMA task pendente (race condition). Com 1 worker, a execução é FIFO (First In, First Out) e nunca há conflito. O Redis garante a atomicidade.

**Como escalar os subagentes?** Basta alterar o `processes` no config do Horizon para a fila `executors`. Em um servidor com mais RAM, pode-se subir para 5 ou 10 workers paralelos. O sistema se adapta automaticamente porque cada SubagentJob já sabe qual subtask processar (via `subtask_id`).

### 3.3. Classes Laravel Envolvidas (Mapa do Código — Laravel 13)

```text
app/
├── Ai/
│   ├── Agents/
│   │   ├── OrchestratorAgent.php        ← implements Agent, HasStructuredOutput, HasTools — Planner
│   │   ├── QAAuditorAgent.php           ← implements Agent, HasStructuredOutput — Judge
│   │   ├── BackendSpecialist.php        ← implements Agent, Conversational, HasTools — Executor
│   │   ├── FrontendSpecialist.php       ← implements Agent, Conversational, HasTools — Executor
│   │   ├── FilamentSpecialist.php       ← implements Agent, Conversational, HasTools — Executor
│   │   ├── DatabaseSpecialist.php       ← implements Agent, Conversational, HasTools — Executor
│   │   ├── DevOpsSpecialist.php         ← implements Agent, Conversational, HasTools — Executor
│   │   ├── SecuritySpecialist.php       ← implements Agent, HasTools — Auditor de Segurança
│   │   ├── PerformanceAnalyst.php       ← implements Agent, HasStructuredOutput — Analista
│   │   └── ContextCompressor.php        ← implements Agent (usa Ollama) — Compressão
│   └── Tools/
│       ├── ShellTool.php                ← implements Laravel\Ai\Contracts\Tool
│       ├── FileTool.php                 ← implements Laravel\Ai\Contracts\Tool
│       ├── GitTool.php                  ← implements Laravel\Ai\Contracts\Tool
│       ├── DatabaseTool.php             ← implements Laravel\Ai\Contracts\Tool
│       ├── SearchTool.php               ← implements Laravel\Ai\Contracts\Tool
│       ├── TestTool.php                 ← implements Laravel\Ai\Contracts\Tool
│       ├── SecurityTool.php             ← implements Laravel\Ai\Contracts\Tool
│       ├── DocsTool.php                 ← implements Laravel\Ai\Contracts\Tool
│       └── MetaTool.php                 ← implements Laravel\Ai\Contracts\Tool
│
├── Jobs/
│   ├── ProcessTaskJob.php               ← Orquestra o pipeline Agent→QA→Git (simplificado)
│   └── ContextCompressionJob.php        ← Comprime sessão quando atinge threshold 0.6
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
│   ├── PromptFactory.php            ← Monta contexto dinâmico (padrões TALL + RAG) — simplificado
│   ├── FileLockManager.php          ← Mutex de arquivos para subtasks paralelas
│   ├── PRDValidator.php             ← Valida PRD contra o JSON Schema (usa JsonSchema do SDK)
│   └── TaskOrchestrator.php         ← Coordena o pipeline Agent→QA→Git
│
├── Models/
│   ├── Project.php
│   ├── ProjectModule.php
│   ├── Task.php
│   ├── Subtask.php
│   ├── AgentConfig.php
│   ├── ContextLibrary.php
│   ├── TaskTransition.php
│   ├── AgentExecution.php
│   ├── ToolCallLog.php
│   ├── ProblemSolution.php
│   └── WebhookConfig.php
│
├── Enums/
│   ├── TaskStatus.php               ← pending, in_progress, qa_audit, testing, completed, etc.
│   ├── SubtaskStatus.php            ← pending, running, qa_audit, success, error, blocked
│   ├── AgentProvider.php            ← gemini, anthropic, ollama
│   ├── TaskSource.php               ← manual, webhook, sentinel, ci_cd
│   ├── KnowledgeArea.php            ← backend, frontend, database, filament, devops, security, performance
│   └── SecuritySeverity.php         ← critical, high, medium, low, informational
│
└── Filament/
    └── Resources/
        ├── ProjectResource.php       ← CRUD de projetos
        ├── TaskResource.php          ← CRUD de tasks + visualização de status em tempo real
        ├── AgentConfigResource.php   ← Configuração de agentes (system prompts, modelos)
        ├── ContextLibraryResource.php ← Gestão dos padrões de código TALL
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
   a. Consultar `problems_solutions` via busca semântica usando o `prd_payload` da task.
      → `whereVectorSimilarTo()` do Eloquent (pgvector nativo no PostgreSQL) retorna os Top 3
        problemas+soluções mais similares ao PRD atual por similaridade de cosseno (>0.7).
      → Isso evita repetir erros. Se a task é "Criar Resource de Posts" e no passado 
        um Resource similar falhou por falta de `$table` property, essa informação será injetada.
   b. Consultar `context_library` WHERE knowledge_area IN (áreas da task) AND is_active = true.
      → Carrega os padrões de código TALL que o agente DEVE seguir.
   c. Carregar histórico de conversa via `RemembersConversations` (SDK nativo — tabela `agent_conversations`).
      → `AgentClass::make()->continueLastConversation($user)->prompt(...)` resgata automaticamente
        as últimas 100 mensagens da conversa persistida no PostgreSQL.
   d. Compilar o [Contexto Global] juntando: Padrões TALL + Soluções passadas + Histórico.

4. [PLANEJAMENTO VIA PRD] (Planner: 'ORCHESTRATOR')
   → O OrchestratorJob monta o prompt:
     [System Prompt do Orchestrator] + [Contexto Global] + [PRD Principal da Task]
   → Envia para o LLM (preferencialmente Claude Sonnet 4-6 por precisão no planejamento).
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
     b. O SubagentJob monta o Prompt:
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
     → O LLM responde com um JSON estruturado:
       {
         "approved": true/false,
         "issues": ["descrição do problema 1", ...],
         "severity": "critical" | "minor" | "cosmetic",
         "suggestion": "como corrigir"
       }
     → Se APROVADO:
       → Marcar subtask como 'success'. Registrar em task_transitions.
     → Se REJEITADO:
       → Incrementar subtask.retry_count.
       → Se retry_count < max_retries (default: 3):
         → Salvar feedback em subtask.qa_feedback.
         → Reverter status para 'pending'.
         → Despachar novo SubagentJob com o feedback do QA incluído no prompt.
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
         → Roda Dusk ou requests simulados contra as rotas do projeto
         → Cada query lazy-loaded é reportada com arquivo e linha
      
      b. Verificação de Índices Missing:
         → Para cada Model do projeto, analisa as queries mais comuns
         → Roda `EXPLAIN` nas queries e verifica se estão usando index scan
         → Sugere criação de índices via migration
      
      c. Dusk Browser Simulation (Validação Real):
         → Roda `php artisan dusk` para simular um USUÁRIO REAL navegando:
           - Preenche formulários com dados realistas (via Factory)
           - Clica em botões, navega entre páginas
           - Verifica que JavaScript (Alpine.js/Livewire) funciona
           - Captura screenshots em cada passo para evidência visual
         → Se Dusk falhar:
           - Captura screenshot do erro via TestTool.action = "screenshot"
           - Inclui o screenshot no relatório para análise multimodal
           - Cria subtask de correção com o screenshot como contexto
      
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
        "dusk_passed": true/false,
        "dusk_screenshots": ["/storage/screenshots/..."],
        "slow_routes": [{"route": "/posts", "time_ms": 780}],
        "recommendations": ["Adicionar eager loading em PostController@index: Post::with('comments')"]
      }

    → Se PASSAR: Avançar para CI/CD.
    → Se n_plus_1 ou slow_routes detectados: Criar subtask de otimização (prioridade média).
    → Se dusk FALHAR: Criar subtask de correção (prioridade alta).
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

    **Camada 1: CI/CD Testing (Testes Unitários + Integração + Browser)**
    → O servidor de testes roda a suite COMPLETA em 3 etapas:
      Etapa 1: `php artisan test --parallel` (Pest/PHPUnit — backend)
      Etapa 2: `php artisan dusk` (Dusk — simulação browser com dados reais)
      Etapa 3: `php artisan enlightn` (Enlightn — segurança + performance)
    → POR QUE rodar Dusk AQUI também (além do passo 10)?
      Porque o passo 10 testa o código no branch da task. O passo 12 testa 
      APÓS o merge no main — pode haver conflitos que quebraram a aplicação.
      O Dusk aqui valida que a aplicação COMPLETA funciona, não apenas a feature nova.
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
   → Ex: Se o Gemini Flash tem janela de 1M tokens e o prompt está com 600K → ratio = 0.6

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

Para provedores que suportam (Anthropic Claude e Google Gemini), o sistema estrutura o prompt para maximizar cache hits:

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

A API do Claude (Anthropic) e do Gemini (Google) identificam blocos cacheáveis 
pela POSIÇÃO no prompt. Se os primeiros milhares de tokens forem IDÊNTICOS entre 
duas chamadas, o provedor usa a versão em cache e cobra ~1/10 do preço.

Ao colocar conteúdo estático PRIMEIRO e dinâmico por ÚLTIMO, maximizamos 
a chance de cache hit. Se fizéssemos o contrário (PRD primeiro, System Prompt depois),
o cache NUNCA seria aproveitado porque o início do prompt mudaria a cada chamada.
```

**Implementação no `PromptFactory.php`:**
O PromptFactory é o serviço responsável por montar o prompt completo. Ele segue rigorosamente a ordem acima. A Anthropic exige uma flag `cache_control: {"type": "ephemeral"}` nos blocos que devem ser cacheados. O Gemini faz isso automaticamente se os primeiros tokens forem idênticos.

### 5.3. RAG Vetorial via pgvector (Long-term)

*   **O que salvar:** Sempre que uma `Task` finaliza com sucesso, o PRD original e o *diff* do código vencedor são vetorizados e salvos na tabela `problems_solutions`.
*   **Como gerar embeddings:** Via Laravel AI SDK (`AI::embeddings()->provider(Lab::OpenAI)->embed(...)`) ou modelo local via Ollama. O SDK suporta múltiplos provedores de embeddings (OpenAI, Gemini, Cohere, Jina, VoyageAI).
*   **Onde armazenar:** Coluna `vector(1536)` na tabela `problems_solutions` via **pgvector** nativo no PostgreSQL 16. Eliminamos a necessidade de ChromaDB ou SQLite-Vec — busca vetorial nativa no mesmo banco relacional.
*   **Como usar:** No passo 3 do fluxo, uma busca semântica traz o Top 3 de contextos relevantes:
    - O PRD atual é vetorizado via `AI::embeddings()`.
    - O vetor é comparado via `whereVectorSimilarTo()` do Eloquent (pgvector).
    - Os 3 registros com maior similaridade de cosseno (>0.7) são injetados no prompt como few-shot.
*   **Exemplo prático:** O Orchestrator recebe um PRD "Criar sistema de notificações em tempo real". O RAG encontra que 2 meses atrás uma task similar ("Criar chat em tempo real") usou WebSocket via Laravel Reverb. Essa solução é injetada, e o agente reutiliza a mesma abordagem validada.

---

## 6. Engenharia de Prompts e Injeção de Padrões

O AI-Dev adota diretrizes estritas para a construção do *System Prompt*, baseadas no cruzamento relacional das tabelas de conhecimento e na economia agressiva de tokens. O documento completo está em `PROMPTS.md`.

### 6.1. Injeção Dinâmica Baseada em Áreas de Conhecimento

A tabela `agents_config` possui o campo `knowledge_areas` (JSON array de áreas).
A **Prompt Factory** (`PromptFactory.php`) usa isso para fazer uma "Injeção Cirúrgica":

**Exemplo concreto:**

```text
Cenário: Task com PRD sobre "Erro de Layout no dashboard do Filament"

1. O PromptFactory detecta as knowledge_areas relevantes: ["frontend", "filament"]

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

Camada 2: REGRAS DO PROVEDOR (específicas do motor LLM)
  → Se Gemini: caminhos absolutos, --no-interaction, paralelismo
  → Se Claude: evitar abandono, recuperação de falha
  → Definidas em PROMPTS.md seção 2

Camada 3: ROLE (específica do tipo de agente)
  → O texto em agents_config.role_description
  → Ex: "Você é um especialista em backend Laravel 13. Sua responsabilidade
         é criar Controllers, Models, Services e Migrations..."

Camada 4: CONTEXTO DINÂMICO (montado em runtime pelo PromptFactory)
  → Padrões TALL relevantes (context_library)
  → Soluções passadas (problems_solutions via RAG)
  → Histórico comprimido (agent_conversations via RemembersConversations)
```

### 6.3. Motores de IA e Gestão de Sessão (Contexto Infinito por Projeto)

O AI-Dev opera com um sistema de **Inferência Dupla**, permitindo alternar entre o poder bruto do Google e o raciocínio de elite da Anthropic:

*   **Motor Gemini (O Executor Veloz):** Utilizaremos o SDK nativo Laravel AI SDK (`laravel/ai`) para modelos como o `gemini-3.1-flash-lite-preview`. O ID da sessão é gerenciado automaticamente nas tabelas de conversas do SDK no PostgreSQL. Isso garante que cada sistema desenvolvido tenha sua própria linha do tempo de aprendizado persistente.

*   **Motor Claude (O Cérebro de Elite):** Integração com o CLI oficial da Anthropic (`@anthropic-ai/claude-code`) para acessar modelos como `Claude Sonnet 4-6` e `Claude Opus 4-6`. Este motor será priorizado para tarefas de alta complexidade como a quebra de PRDs pelo `ORCHESTRATOR` e a auditoria pelo `QA_AUDITOR`. O motivo: Claude demonstra raciocínio mais rigoroso e menor taxa de alucinação em tarefas de planejamento.

*   **Motor Ollama (O Compressor Local):** Modelo ultraleve rodando permanentemente no servidor (ex: `qwen2.5:0.5b` ou `llama3.2:1b` — ambos cabem em ~500MB de RAM). Sua ÚNICA função é comprimir contexto e gerar embeddings — nunca é usado para gerar código ou planejar. Isso poupa os tokens caros dos modelos maiores.

*   **Gestão Distribuída de Contexto:** O UUID da conversa ativa é armazenado na tabela `projects` (`gemini_session_id` / `claude_session_id`) e gerenciado pelo trait `RemembersConversations` do SDK. A cada nova chamada, `AgentClass::make()->continue($conversationId, $user)->prompt(...)` resgata automaticamente o histórico do PostgreSQL.

**Seleção Automática de Motor via Laravel AI SDK:**

```text
SDK default (config/ai.php): provider 'openai', model 'gpt-5-nano' — fallback geral

Cada Agent class define provider/model via PHP Attributes com array de fallback:

// ORCHESTRATOR — Gemini principal, Claude backup
#[Provider([Lab::Gemini, Lab::Anthropic])]
#[Model('gemini-2.0-flash')]
class OrchestratorAgent implements Agent, HasStructuredOutput, HasTools { ... }
→ Gemini tem maior cota; orquestrador é chamado com alta frequência

// AGENTS SPECIALISTS — Claude principal, Gemini backup
#[Provider([Lab::Anthropic, Lab::Gemini])]
#[Model('claude-sonnet-4-6')]
class BackendSpecialist implements Agent, Conversational, HasTools { ... }
→ Claude gera código mais preciso e com menos alucinações

// CONTEXT COMPRESSOR — Ollama local, sem custo de API
#[Provider(Lab::Ollama)]
#[Model('qwen2.5:0.5b')]
class ContextCompressor implements Agent { ... }
→ Modelo leve local, sem custo, 500MB RAM

O provider pode ser sobrescrito em runtime:
  $agent->prompt($prompt, provider: Lab::Anthropic, model: 'claude-opus-4-6')

Hierarquia de seleção: chamada explícita > PHP Attribute > agents_config > config('ai.default')
O SDK dispara AgentFailedOver event e tenta o próximo provider no array automaticamente.
```

---

## 7. Arsenal de Ferramentas (The Tool Layer) e MCP Isolado

As ferramentas são classes PHP em `app/Ai/Tools/` que implementam o contrato `Tool` do **Laravel AI SDK** (`laravel/ai`). O SDK gerencia automaticamente o dispatch das tool calls via `handle(Request $request)`, validando parâmetros contra o `schema(JsonSchema $schema)` de cada ferramenta — sem necessidade de ToolRouter custom.

O catálogo completo de ferramentas, com schemas de entrada/saída e exemplos práticos, está documentado em `FERRAMENTAS.md`. Abaixo temos o resumo consolidado:

### Ferramentas Consolidadas (10 Ferramentas Atômicas — `implements Tool`)

| # | Ferramenta | Classe | Ações Principais |
|---|---|---|---|
| 1 | **ShellTool** | `App\Ai\Tools\ShellTool` | Executar comandos de terminal (artisan, npm, composição), com timeout, sandbox e logs |
| 2 | **FileTool** | `App\Ai\Tools\FileTool` | Ler, criar, editar (patch/diff), renomear, mover, deletar arquivos. Navegação de diretórios |
| 3 | **DatabaseTool** | `App\Ai\Tools\DatabaseTool` | DDL (migrations), DML (queries), dump, restore, describe, seed |
| 4 | **GitTool** | `App\Ai\Tools\GitTool` | add, commit, push, pull, branch, merge, diff, stash, revert + API GitHub |
| 5 | **SearchTool** | `App\Ai\Tools\SearchTool` | Pesquisa web (DuckDuckGo) + scraping inteligente (Firecrawl self-hosted) |
| 6 | **TestTool** | `App\Ai\Tools\TestTool` | PHPUnit/Pest, Dusk, screenshots de falha, cobertura |
| 7 | **SecurityTool** | `App\Ai\Tools\SecurityTool` | Enlightn, Larastan, Nikto, SQLMap, OWASP ZAP, dependency audit |
| 8 | **DocsTool** | `App\Ai\Tools\DocsTool` | Criar/atualizar Markdown, TODOs, documentação técnica |
| 9 | **SocialTool** | `App\Ai\Tools\SocialTool` | Publicar em redes sociais via `hamzahassanm/laravel-social-auto-post` (Facebook, Instagram, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest, Telegram) |
| 10 | **MetaTool** | `App\Ai\Tools\MetaTool` | Criar novas ferramentas dinamicamente + logging de impossibilidades |

**Por que consolidamos de 18+ para 10?** Muitos agentes LLM ficam confusos quando têm dezenas de ferramentas com nomes similares. Eles desperdiçam tokens "decidindo" entre `FileArchitectTool` e `FileSystemNavigatorTool`. Com ferramentas consolidadas e sub-ações claras (ex: `FileTool.action = "read"` vs `FileTool.action = "write"`), a IA gasta menos tempo decidindo e mais tempo agindo.

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
| **Git push falha** | GitTool tenta `git pull --rebase` + `git push` novamente. Se conflito: marca como `escalated` |
| **API do LLM fora do ar** | O SDK faz failover automático via `AgentFailedOver` event. O `fallback_agent_id` em `agents_config` define o provedor substituto. Ex: se Claude cair, usa Gemini |
| **Duas subtasks editam o mesmo arquivo** | FileLockManager impede (status `blocked`). Nunca acontece race condition |
| **Sentinela em loop (mesmo erro)** | Hash de dedup impede criação de tasks duplicadas. Após 3 falhas: `requires_human` |
| **Compressão de contexto corrompida** | O histórico completo fica em disco (`full_history_path`). Pode ser restaurado manualmente |
| **Modelo local (Ollama) offline** | ContextCompressionJob faz retry com backoff exponencial. Se Ollama não voltar em 5 min, usa Gemini Flash como fallback para comprimir (mais caro, mas funciona) |

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
  - Configurar `config/ai.php` com providers Anthropic + Gemini
- [ ] Implementar 3 Tools SDK: `ShellTool`, `FileTool`, `GitTool` (`implements Tool`)
- [ ] Implementar `OrchestratorJob`, `SubagentJob`, `QAAuditJob` (despachados via Laravel Queue)
- [ ] Configurar Horizon + Supervisor para as 4 filas principais
- [ ] Teste end-to-end: Criar uma task "Criar Model de Post" e ver o sistema executar sozinho

### Fase 2: Qualidade e Observabilidade
**Objetivo:** Adicionar camadas de segurança, auditoria e a interface de gestão.

- [ ] Criar Migrations para: `agent_executions`, `tool_calls_log`, `context_library`
- [ ] Implementar Filament Resources para Projects, Tasks, AgentConfig
- [ ] Implementar Dashboard com widgets de métricas (custo, saúde de workers)
- [ ] Implementar `TestTool` + `DatabaseTool` + `SecurityTool` (SDK Tool contract)
- [ ] Criar o Sentinela (Exception Handler para projetos alvo)
- [ ] Implementar Git branching por task + FileLockManager para subtasks paralelas
- [ ] Implementar circuit breakers (limites de custo, retries, tempo)

### Fase 3: Inteligência e Memória
**Objetivo:** Adicionar memória vetorial, compressão de contexto e auto-evolução.

- [ ] Criar Migration para: `problems_solutions` (com coluna `vector(1536)` via pgvector)
- [ ] Implementar `ContextCompressor` Agent (Ollama local) + `ContextCompressionJob`
- [ ] Implementar RAG vetorial via `whereVectorSimilarTo()` (pgvector nativo — sem ChromaDB)
- [ ] Implementar `toEmbeddings()` via SDK para vetorizar problemas/soluções
- [ ] Implementar Prompt Caching (ordem correta: estático → semi-estático → dinâmico)
- [ ] Implementar `SearchTool` (DuckDuckGo + Firecrawl self-hosted)
- [ ] Implementar `ProblemSolutionRecorder` (auto-alimentação via Listener)
- [ ] Implementar webhooks de entrada (GitHub, CI/CD)
- [ ] Implementar `SocialTool` + migration `social_accounts` + Filament Resource

---

## 11. Integração com Redes Sociais (SocialTool)

O AI-Dev integra publicação em redes sociais via o pacote **`hamzahassanm/laravel-social-auto-post`** (v2.2+). Isso permite que os agentes publiquem conteúdo automaticamente nas plataformas do projeto — lançamentos, relatórios de progresso, notificações de deploy — sem intervenção humana.

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

### 11.3. O SocialTool (Tool SDK)

```php
// app/Ai/Tools/SocialTool.php
class SocialTool implements Tool
{
    public function description(): string
    {
        return 'Publish content to social media platforms for the active project.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action'    => $schema->string()->enum(['share', 'share_to_all', 'upload_video']),
            'platforms' => $schema->array()->items($schema->string())->nullable(),
            'message'   => $schema->string(),
            'url'       => $schema->string()->nullable(),
            'media_path'=> $schema->string()->nullable()->description('Absolute path to image/video'),
        ];
    }

    public function handle(Request $request): string
    {
        $platforms = $request->get('platforms');
        $message   = $request->get('message');
        $url       = $request->get('url');

        if ($platforms) {
            SocialMedia::share($platforms, $message, $url);
        } else {
            SocialMedia::shareToAll($message);
        }

        return json_encode(['success' => true, 'platforms' => $platforms ?? 'all']);
    }
}
```

### 11.4. Casos de Uso Agênticos

Os agentes podem usar o `SocialTool` automaticamente nos seguintes contextos:

1. **Deploy concluído** — O `DevOpsSpecialist` após um deploy bem-sucedido pode publicar um anúncio no LinkedIn e Twitter do projeto.
2. **Feature nova** — O `QAAuditorAgent` após aprovar uma feature pode publicar um post de lançamento.
3. **Relatório semanal** — Uma task agendada pode gerar e publicar relatório de progresso em todas as redes.
4. **Notificação de manutenção** — Aviso automático via Telegram antes de manutenções programadas.

### 11.5. Gerenciamento via Filament UI

Um `SocialAccountResource` no Filament permitirá:
- Cadastrar e testar credenciais por plataforma por projeto
- Visualizar histórico de publicações (tabela `social_posts_log`)
- Ativar/desativar plataformas por projeto
- Preview e agendamento de posts

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

## 16. Segurança na Invocação dos LLMs (Sandboxing)

### 14.1. Princípio Fundamental: IA Retorna Texto, Sistema Executa

Os LLMs (Gemini e Claude) **NUNCA** têm acesso direto ao sistema operacional, ao banco de dados, ao filesystem ou a qualquer recurso do servidor. Toda interação com o ambiente é mediada exclusivamente pelas **Tool classes** do Laravel AI SDK.

A IA recebe um prompt, retorna tool calls estruturadas, e o SDK valida o schema (`schema(JsonSchema $schema)`) e executa `handle(Request $request)`. A IA **nunca** executa nada diretamente — sempre passa pelo contrato `Tool`.

### 14.2. Flags Obrigatórias nos Proxies

**Proxy Claude (`infrastructure/proxy/claude_proxy.py` — porta 8002):**
```bash
claude -p \                           # --print: modo não-interativo, sem confirmações
    --tools "" \                       # Desabilita TODAS as ferramentas internas (Bash, Edit, Read, etc.)
    --permission-mode plan \           # Modo read-only: impede qualquer escrita direta no OS
    --model <modelo> \
    --session-id <project.claude_session_id> \
    "<prompt>"
```

**Proxy Gemini (`infrastructure/proxy/gemini_proxy.py` — porta 8001):**
```bash
gemini --sandbox \                     # Modo sandboxed: impede acesso direto ao filesystem
    --approval-mode plan \             # Modo read-only: sem execução direta de comandos
    -m <modelo> \
    -r <project.gemini_session_id> \   # Resume sessão do projeto para manter contexto
    -p "<prompt>"
```

**Por que essas flags?**
- `--tools ""` / `--sandbox`: Garante que a IA não pode executar `rm`, `chmod`, `DROP TABLE` ou qualquer comando diretamente. Tudo passa pelo nosso `ShellTool` que tem lista de comandos bloqueados.
- `--permission-mode plan` / `--approval-mode plan`: Modo read-only — a IA só consegue retornar texto. Não pode modificar nada.
- `-p` / `--prompt`: Modo não-interativo — sem perguntas "Deseja continuar? (y/n)". O proxy recebe o prompt, obtém a resposta e retorna imediatamente.

### 14.3. Session ID Obrigatório por Projeto

O conversation ID da sessão SDK é armazenado na tabela `projects`:

- Provider `gemini` → `project.gemini_session_id`
- Provider `anthropic` → `project.claude_session_id`

O trait `RemembersConversations` do SDK gerencia automaticamente a continuidade:
```php
$agent = BackendSpecialist::make();

// Nova conversa (1ª chamada para o projeto)
$response = $agent->forUser($systemUser)->prompt($prompt);
$project->update(['gemini_session_id' => $response->conversationId]);

// Continuar conversa (chamadas subsequentes)
$response = $agent->continue($project->gemini_session_id, as: $systemUser)->prompt($prompt);
```
Isso garante que:
1. A IA mantém contexto entre chamadas (memória da conversa persistida no PostgreSQL)
2. Cada projeto tem seu próprio contexto isolado
3. O contexto da IA complementa a memória vetorial do AI-Dev (dupla camada de memória)
