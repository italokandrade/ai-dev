# 🤖 AI-Dev (AndradeItalo.ai)

**Plataforma Master de Desenvolvimento Autônomo, Multi-Agente e Auto-Corretivo.**

O AI-Dev é uma aplicação Laravel 13 independente, com seu próprio repositório, banco, dependências e Boost MCP, cuja missão é **orquestrar o ciclo completo de vida** (desenvolvimento, refatoração, auditoria, manutenção) de **outras** aplicações Laravel. Cada aplicação operada pelo AI-Dev (chamada de **Projeto Alvo**) é também um sistema Laravel autônomo, com seu próprio repositório, banco, dependências e Boost MCP — mas **não contém agentes de desenvolvimento**: quem desenvolve é o AI-Dev, consumindo o Boost MCP do próprio Projeto Alvo para obter o contexto exato daquele projeto (schema, docs instaladas, estado do código).

---

## 🏛 Arquitetura em Duas Camadas (Authoritative)

O ecossistema tem **duas classes de aplicações Laravel**, cada uma com responsabilidades e componentes distintos. Esta tabela é a fonte única — todos os outros documentos referenciam esta seção.

| Componente | **ai-dev-core** (Master) | **Projeto Alvo** (operado pelo ai-dev-core) |
|---|---|---|
| Repositório GitHub | `ai-dev` (próprio) | Repositório próprio, independente |
| Codebase | `/var/www/html/projetos/ai-dev/ai-dev-core` | `/var/www/html/projetos/<nome>` |
| Banco de dados | `ai_dev_core` (projects, tasks, subtasks, agents_config…) | Banco próprio com tabelas do domínio de negócio |
| Dependências Composer | `laravel/ai`, `laravel/mcp`, `laravel/boost` (dev), Filament v5, Horizon | Mesma base TALL + pacotes específicos ao negócio |
| Boost MCP | Instalado (usado pelo Claude Code no desenvolvimento do próprio ai-dev-core) | Instalado (consumido pelos agentes do ai-dev-core durante execução de tasks) |
| Admin Panel (Filament) | Gerencia projetos, tasks, quotations, agents_config | Gerencia as entidades de negócio do projeto |
| **IAs de Interação com o Sistema** *(falam com o usuário no Admin Panel)* | `RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent` | AIs específicas do negócio (ex: copiloto do usuário final, classificação, sumarização — definidas na spec de cada projeto) |
| **IAs de Desenvolvimento** *(escrevem código no codebase)* | `OrchestratorAgent`, `SpecialistAgent`, `QAAuditorAgent`, `DocsAgent` | **Nenhuma** — o ai-dev-core escreve código no Projeto Alvo usando o Boost dele |
| Workers / Filas | `queue:work` processando tasks → operam sobre Projetos Alvo | Workers próprios para jobs de negócio do projeto |
| `.env` AI | `OPENROUTER_API_KEY` (para agentes de desenvolvimento + AIs de interação) | `OPENROUTER_API_KEY` próprio (para AIs de interação do projeto) |

**Princípio da isolação:** nenhum estado do ai-dev-core vaza para o Projeto Alvo e vice-versa. O acoplamento é por **filesystem** (`local_path` na tabela `projects`) + **MCP** (Boost do projeto alvo como fonte de contexto). Projetos podem ter versões diferentes de Laravel, Filament ou dependências — o ai-dev-core se adapta ao que o Boost do alvo reportar.

---

## 🏗️ Stack Obrigatória

Esta é a stack do próprio **ai-dev-core** e também a stack **default** que `instalar_projeto.sh` provisiona para cada Projeto Alvo. Projetos podem divergir posteriormente (versões de pacotes específicos), mas o fundamento é comum.

| Camada | Tecnologia |
|---|---|
| **Backend** | Laravel 13 + PHP 8.3 |
| **Frontend** | Livewire 4 + Alpine.js v3 + Tailwind CSS v4 |
| **Admin Panel** | Filament v5 |
| **Animações** | Anime.js |
| **Banco Relacional** | PostgreSQL 16 + pgvector (busca vetorial nativa) |
| **Filas/Cache** | Redis 7.0 |
| **AI SDK** | Laravel AI SDK (`laravel/ai` v0.5) — Agents, Tools, Structured Output, Conversations |
| **MCP** | Laravel MCP (`laravel/mcp` v0.6) — Model Context Protocol |
| **Boost** | Laravel Boost (`laravel/boost` v2.4) — instalado em **cada** aplicação: no ai-dev-core para o desenvolvimento via Claude Code, e em cada Projeto Alvo como fonte de contexto consumida pelos agentes do ai-dev-core |
| **Planejamento (ai-dev-core)** | OpenRouter → `anthropic/claude-opus-4.7` — OrchestratorAgent, SpecificationAgent, QuotationAgent, RefineDescriptionAgent |
| **Código/QA (ai-dev-core)** | OpenRouter → `anthropic/claude-sonnet-4-6` — SpecialistAgent, QAAuditorAgent |
| **Docs/Rápido (ai-dev-core)** | OpenRouter → `anthropic/claude-haiku-4-5-20251001` — DocsAgent |
| **SDK Default** | OpenRouter (família Anthropic) — usado tanto pelos agentes do ai-dev-core quanto pelas AIs de interação instaladas nos Projetos Alvo |
| **Orquestração** | Laravel Horizon v5 (filas Redis) — Supervisor planejado para fase futura |
| **Testes** | Pest v4 + PHPUnit v12 (Dusk removido de ambos os lados) |
| **IA Local** | Ollama — planejado (fase futura): qwen2.5:0.5b (compressão) + nomic-embed-text (embeddings) |
| **Redes Sociais** | `hamzahassanm/laravel-social-auto-post` — planejado (fase futura) |

---

## 📐 Fluxo Operacional (ai-dev-core → Projeto Alvo)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ai-dev-core (Master)                                                    │
│                                                                         │
│  Humano/Webhook                                                         │
│      │                                                                  │
│      ▼  preenche no Admin Panel (Filament)                              │
│  [RefineDescriptionAgent, SpecificationAgent, QuotationAgent]           │  ← IAs de interação
│      │  (refina descrição, gera spec técnica, estima custo)             │
│      ▼                                                                  │
│  Task criada + PRD (prd_payload JSON) + Project.local_path              │
│      │                                                                  │
│      ▼                                                                  │
│  OrchestratorAgent (Opus 4.7)  →  Sub-PRDs                              │  ← IAs de desenvolvimento
│      │                                                                  │
│      ▼                                                                  │
│  SpecialistAgent (Sonnet 4.6) ──┐                                       │
│      │                          │                                       │
│      │           consome via MCP│                                       │
│      │                          ▼                                       │
│      │   ┌───────────────────────────────────────────────────────┐      │
│      │   │ Projeto Alvo (local_path)                              │     │
│      │   │   - Código-fonte (FileRead/Write no path do alvo)      │     │
│      │   │   - Boost MCP instalado (schema, docs, browser-logs)   │     │
│      │   │   - Git repo próprio (commits feitos no repo do alvo)  │     │
│      │   │   - Banco de dados próprio                             │     │
│      │   │   - AIs de interação próprias (independentes)          │     │
│      │   └───────────────────────────────────────────────────────┘     │
│      │                                                                  │
│      ▼                                                                  │
│  QAAuditorAgent (Sonnet 4.6) — audita diff + Boost do alvo              │
│      │                                                                  │
│      ▼                                                                  │
│  Git push no repo DO ALVO + Sentinela vigia runtime do alvo             │
└─────────────────────────────────────────────────────────────────────────┘
```

**Pontos-chave:**

- `SpecialistAgent`, `QAAuditorAgent` e `DocsAgent` recebem o `project.local_path` ao serem instanciados. Todas as ferramentas de filesystem/shell/git (`FileReadTool`, `FileWriteTool`, `ShellExecuteTool`, `GitOperationTool`) já são escopadas ao path do Projeto Alvo via constructor.
- O `BoostTool` deve ser instanciado com o mesmo `local_path` e roteia para `php artisan boost:*` dentro do Projeto Alvo, garantindo que o agente leia o schema e a docs **do alvo**, não do ai-dev-core. _Status de implementação: BoostTool atual opera no contexto do ai-dev-core — pendente tornar project-path-aware._
- `DocsAgent` (`BoostTool.search-docs`) pesquisa a documentação instalada no **Boost do Projeto Alvo**, refletindo as versões exatas de Laravel/Filament/Livewire que aquele projeto tem instaladas.

---

## 📁 Documentação

| Arquivo | Conteúdo |
|---|---|
| [ARCHITECTURE.md](./ARCHITECTURE.md) | Visão completa: banco, protocolo inter-agentes, roteamento MCP entre ai-dev-core e Projetos Alvo, máquina de estados, métricas, fases |
| [ADMIN_GUIDE.md](./ADMIN_GUIDE.md) | Uso do Admin Panel Filament do ai-dev-core — onde o humano cria projetos e tasks |
| [PRD_SCHEMA.md](./PRD_SCHEMA.md) | JSON Schema formal do PRD (Product Requirement Document) e Sub-PRD |
| [STANDARD_MODULES.md](./STANDARD_MODULES.md) | Catálogo de Módulos Padrão (Core Master) injetados em cada Projeto Alvo |
| [FERRAMENTAS.md](./FERRAMENTAS.md) | Catálogo das 6 ferramentas atômicas consumidas pelos agentes |
| [PROMPTS.md](./PROMPTS.md) | Engenharia de prompts: regras universais, role descriptions, segurança anti-injection |
| [INFRASTRUCTURE.md](./INFRASTRUCTURE.md) | Requisitos de servidor, supervisor, Ollama, pgvector, ferramentas de segurança |
| [MIGRATION_LARAVEL13.md](./MIGRATION_LARAVEL13.md) | Histórico de migração para Laravel 13 + AI SDK (arquivado) |

---

## 🗄️ Modelagem do Banco de Dados (ai-dev-core)

Todas as tabelas abaixo vivem no banco **`ai_dev_core`**. Elas descrevem e auditam a operação do ai-dev-core sobre os Projetos Alvo.

A estrutura hierárquica é: **Projeto → Módulos → Submódulos → Tasks → Subtasks**. Cada nível é subdividido ao máximo para que a automação processe unidades pequenas e precisas.

**Tabelas implementadas (Fase 1 — operacionais):**

| Tabela | Propósito |
|---|---|
| `projects` | Cadastro de Projetos Alvo (repo, `local_path`, stack, env, com PRD do Sistema Inteiro em JSON) |
| `project_specifications` | Especificação técnica gerada pelo SpecificationAgent; aprovação auto-cria módulos/submódulos |
| `project_modules` | Módulos e submódulos (hierárquico via parent_id): Projeto → Módulos → Submódulos, com PRD em JSON por módulo/submódulo |
| `project_quotations` | Orçamentos com comparativo de custo humano vs. AI-Dev e cálculo de ROI |
| `tasks` | Tarefas vinculadas a submódulos, com PRD em JSON |
| `subtasks` | Decomposição granular feita pelo Orchestrator (sub-PRDs por especialista) |
| `agents_config` | Configuração dinâmica de cada agente (modelo, temperatura, prompt) |
| `task_transitions` | Log de auditoria de toda mudança de estado |
| `agent_conversations` | Conversas persistidas automaticamente pelo Laravel AI SDK |
| `agent_conversation_messages` | Mensagens das conversas (gerenciado pelo SDK) |
| `social_accounts` | Credenciais de redes sociais por Projeto Alvo (criptografadas) |

**Tabelas do Core Master (injetadas em TODOS os Projetos, incluindo o ai-dev-core):**

| Tabela | Propósito |
|---|---|
| `audit_logs` | Log global (read-only) de todas as ações (Insert/Update/Delete) no sistema inteiro |
| `roles` & `permissions` | Perfis de usuários e permissões granulares por módulo (Controle de Acesso) |
| `system_settings` | Configurações do sistema via UI, incluindo credenciais e modelos da IA de interação (evita hardcoding no `.env`) |
| `users` | Cadastro central de usuários vinculados a perfis |

**Tabelas planejadas (Fase 2/3 — pendentes):**

| Tabela | Fase | Propósito |
|---|---|---|
| `agent_executions` | Fase 2 | Log de cada chamada LLM (tokens, custo, latência) |
| `tool_calls_log` | Fase 2 ⚠️ alta prioridade | Registro de cada ferramenta executada — populado via `Tool::dispatched()` listener (auditoria de segurança) |
| `webhooks_config` | Fase 2 | Configuração de webhooks de entrada (GitHub, CI/CD) |
| `context_library` | Fase 3 | Padrões de código TALL obrigatórios (few-shot fixo) |
| `problems_solutions` | Fase 3 | Base de conhecimento auto-alimentada (RAG vetorial via pgvector) |

---

## 🔧 Ferramentas (6 Atômicas — `implements Laravel\Ai\Contracts\Tool`)

Todas as ferramentas vivem em `ai-dev-core/app/Ai/Tools/` e implementam o contrato `Tool` do Laravel AI SDK. **Ferramentas que tocam filesystem, shell ou git recebem `working_directory` (resolvido via `projects.local_path` da task)** e operam exclusivamente dentro do Projeto Alvo — nunca escrevem em `/ai-dev-core`. O `BoostTool` segue o mesmo padrão, roteando para o Boost do Projeto Alvo (ver FERRAMENTAS.md).

| # | Ferramenta | Ações Principais | Escopo |
|---|---|---|---|
| 1 | **BoostTool** | `database-schema`, `search-docs`, `browser-logs`, `last-error` via `php artisan boost:mcp` | Boost do Projeto Alvo |
| 2 | **DocSearchTool** | Busca focada em docs TALL Stack via Boost `search-docs` | Boost do Projeto Alvo |
| 3 | **FileReadTool** | Leitura de arquivos (com limites de linhas/tamanho) | Filesystem do Projeto Alvo |
| 4 | **FileWriteTool** | Escrita/edição de arquivos (com validação de path) | Filesystem do Projeto Alvo |
| 5 | **GitOperationTool** | `status`, `diff`, `add`, `commit`, `branch` | Repo git do Projeto Alvo |
| 6 | **ShellExecuteTool** | Execução controlada de `artisan`, `composer`, `npm`, `php` | Shell no cwd do Projeto Alvo |

---

## 🎯 Fases de Implementação

### Fase 1: Core Loop (MVP) — ✅ Em andamento
- Ciclo completo: Task → OrchestratorAgent → SpecialistAgent → QAAuditorAgent → Git Commit no repo do alvo
- Agent classes com `HasTools` (SDK nativo) + BoostTool obrigatório antes de escrever código
- Provider strategy: openrouter único — Opus 4.7 (planejamento) | Sonnet 4.6 (código/QA) | Haiku 4.5 (docs)
- PostgreSQL 16 + Redis 7 + Laravel Horizon v5
- **Pendente:** tornar `BoostTool` project-path-aware (rotear para `php artisan boost:*` no path do alvo)

### Fase 2: Qualidade, Segurança e UI — prioridades atualizadas
- **[Alta — segurança]** Hardening `BoostTool.database-query`: allowlist tabelas/colunas/operadores, redação de `_token`/`_secret`/`_password`/`_key`, conexão `readonly`, cap 8 000 chars — ver `FERRAMENTAS.md §1`
- **[Alta — auditoria]** `Tool::dispatched()` listener → populate `tool_calls_log` automaticamente
- **[Alta — validação]** `HasStructuredOutput` em `OrchestratorAgent`, `QAAuditorAgent`, `QuotationAgent` — validação de schema JSON pelo SDK, sem parsing manual
- Security Specialist + Performance Analyst
- Sentinel Self-Healing + Enlightn + Larastan + Nikto + SQLMap
- Circuit breakers + Git branching por task no repo do alvo
- Supervisor para workers de longa duração

### Fase 3: IA Avançada
- RAG Vetorial via pgvector nativo no PostgreSQL + Compressão de contexto (Ollama local)
- `SimilaritySearch::usingModel()` como tool SDK nativa para busca semântica (pgvector) — ver `ARCHITECTURE.md §10 Fase 3`

---

## ⚡ Padrão de Desenvolvimento: Boost via MCP (Obrigatório nos Dois Lados)

O Boost é instalado em **cada** aplicação Laravel do ecossistema. A distinção é **quem o consome**:

| Boost instalado em… | Consumidor | Propósito |
|---|---|---|
| `ai-dev-core` | Claude Code (humano) | Desenvolvimento do próprio ai-dev-core |
| Cada Projeto Alvo | Agentes do ai-dev-core (via `BoostTool`) | Contexto exato para gerar/auditar código do alvo |

```
Agente do ai-dev-core recebe Sub-PRD
    → BoostTool(projectPath=/var/www/html/projetos/<alvo>)
    → php artisan boost:search-docs / database-schema / ... (executado NO alvo)
    → Agente recebe contexto fidedigno ao stack instalado no alvo
    → Agente implementa no filesystem do alvo
```

**Benefício real:** O agente não precisa "conhecer" Filament ou Livewire a partir da memória de treinamento. Ele consulta o Boost do Projeto Alvo, que reflete as versões físicas instaladas **naquele** projeto. Zero risco de sugerir API de Filament v4 em projeto com Filament v5.

Veja a seção **17. Laravel Boost + MCP** em `ARCHITECTURE.md` para o fluxo detalhado de roteamento MCP.

---

## 🖥️ Servidor

- **Ubuntu 24.04 LTS** — 2 vCPUs, 8 GB RAM
- **IP:** 10.1.1.86 (Supreme)
- **Consumo total estimado:** ~3.4 GB RAM com todos os componentes rodando
- Todos os Projetos Alvo ficam em `/var/www/html/projetos/<nome>` (path registrado em `projects.local_path`)

---

## 📄 Licença

Projeto proprietário — AndradeItalo.ai © 2026
