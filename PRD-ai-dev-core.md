# PRD — ai-dev-core (Plataforma Master de Desenvolvimento Autônomo)

> **Versão:** 1.0 — 2026-04-20
> **Escopo:** Refatoração completa do ai-dev-core com base na documentação arquitetural consolidada.
> **Referências:** `README.md`, `ARCHITECTURE.md`, `FERRAMENTAS.md`, `PROMPTS.md`, `INFRASTRUCTURE.md`, `PRD_SCHEMA.md`, `ADMIN_GUIDE.md`, `STANDARD_MODULES.md`

---

## 1. Visão Geral do Projeto

O **ai-dev-core** é uma aplicação Laravel 13 standalone, cuja missão é **orquestrar o ciclo completo de desenvolvimento autônomo** (planejamento, codificação, auditoria, testes, commits, rollback) de outras aplicações Laravel — chamadas de **Projetos Alvo**. Ele não expõe produto ao usuário final; ele entrega código nos repositórios dos Projetos Alvo.

### 1.1. Objetivo Central

> Permitir que um humano cadastre um PRD (Product Requirement Document) via Admin Panel do Filament v5 e o sistema execute, de forma totalmente autônoma, o ciclo completo: planejamento → especialistas → QA → segurança → performance → commit — sem intervenção humana, exceto em casos de escalada ou aprovação de orçamento.

### 1.2. Stack Obrigatória (Não Negociável)

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13 + PHP 8.3 |
| Frontend | Livewire 4 + Alpine.js v3 + Tailwind CSS v4 |
| Admin Panel | Filament v5 |
| Banco Relacional | PostgreSQL 16 + pgvector |
| Filas/Cache | Redis 7.0 + Laravel Horizon v5 |
| AI SDK | `laravel/ai` v0.5 (Agents, Tools, HasStructuredOutput, HasTools, Conversational, RemembersConversations) |
| MCP | `laravel/mcp` v0.6 |
| Boost | `laravel/boost` v2.4 |
| LLM Planejamento | OpenRouter → `anthropic/claude-opus-4.7` |
| LLM Código/QA | OpenRouter → `anthropic/claude-sonnet-4-6` |
| LLM Docs | OpenRouter → `anthropic/claude-haiku-4-5-20251001` |
| Testes | Pest v4 + PHPUnit v12 |
| Codebase path | `/var/www/html/projetos/ai-dev/ai-dev-core` |

---

## 2. Arquitetura em Duas Camadas (Princípio Inviolável)

O ai-dev-core **nunca** modifica seu próprio código em produção via agentes. Toda escrita de código, commit e teste ocorre **dentro do Projeto Alvo** (`projects.local_path`). O acoplamento entre camadas é por:

1. **Filesystem** — `projects.local_path` (ex: `/var/www/html/projetos/portal`). Todas as tools (`FileReadTool`, `FileWriteTool`, `ShellExecuteTool`, `GitOperationTool`) são escopadas a esse path.
2. **Boost MCP do Projeto Alvo** — `BoostTool` roteia `php artisan boost:*` **dentro do** `local_path`, refletindo schema, docs e estado real **daquele** projeto.

---

## 3. Módulos do Sistema

### Módulo A — Banco de Dados (Core State)

**Banco:** `ai_dev_core`

#### Tabelas Operacionais (Fase 1 — devem existir e funcionar):

| Tabela | Status Esperado |
|---|---|
| `projects` | ✅ Implementada |
| `project_specifications` | ✅ Implementada |
| `project_modules` | ✅ Implementada |
| `project_quotations` | ✅ Implementada |
| `tasks` | ✅ Implementada |
| `subtasks` | ✅ Implementada |
| `agents_config` | ✅ Implementada |
| `task_transitions` | ✅ Implementada |
| `agent_conversations` | ✅ Implementada (SDK) |
| `agent_conversation_messages` | ✅ Implementada (SDK) |
| `social_accounts` | ✅ Migration criada (integração pendente Fase 3) |

#### Tabelas Planejadas:

| Tabela | Fase | Descrição |
|---|---|---|
| `agent_executions` | Fase 2 | Log de cada chamada LLM (tokens, custo, latência) |
| `tool_calls_log` | Fase 2 | Auditoria de cada tool call executada |
| `webhooks_config` | Fase 2 | Webhooks de entrada (GitHub, CI/CD) |
| `context_library` | Fase 3 | Padrões de código TALL ("Bíblia TALL") |
| `problems_solutions` | Fase 3 | RAG vetorial — pares problema/solução com `vector(1536)` |

#### Máquina de Estados da Task (Obrigatória):

```
pending → in_progress → qa_audit → testing → completed
                                ↘ rejected → in_progress (retry) ou escalated
                    ↘ rollback → failed
```

Toda transição é gravada em `task_transitions`. Transições inválidas são bloqueadas pelo Model.

---

### Módulo B — Agentes de Desenvolvimento

Todos os agentes residem em `app/Ai/Agents/`. Cada um implementa `Agent` do SDK e usa `Promptable` trait.

| Agente | Modelo | Interfaces SDK | Propósito |
|---|---|---|---|
| `OrchestratorAgent` | Opus 4.7 | `Agent, HasStructuredOutput, HasTools` | Quebra PRD em Sub-PRDs; define execution_order; enfileira subtasks |
| `BackendSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Models, Controllers, Services, APIs |
| `FrontendSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Livewire components, Alpine.js, Tailwind |
| `FilamentSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Resources, Widgets, Pages Filament v5 |
| `DatabaseSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Migrations, Enums, Seeders, queries |
| `DevOpsSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Deploy, CI/CD, Supervisor, Docker |
| `TestingSpecialist` | Sonnet 4.6 | `Agent, HasTools` | Pest v4, factories, feature/unit tests |
| `QAAuditorAgent` | Sonnet 4.6 | `Agent, HasStructuredOutput, HasTools` | Audita diff; aprova/rejeita com JSON estruturado |
| `SecuritySpecialist` | Sonnet 4.6 | `Agent, HasStructuredOutput, HasTools` | Enlightn, Nikto, audit de dependências |
| `PerformanceAnalyst` | Sonnet 4.6 | `Agent, HasStructuredOutput, HasTools` | Lighthouse CI, Pest browser tests, métricas |
| `DocsAgent` | Haiku 4.5 | `Agent, HasTools` | Gera/atualiza documentação via Boost search-docs |
| `RefineDescriptionAgent` | Opus 4.7 | `Agent, HasTools` | IA de interação — refina descrição de task no Admin Panel |
| `SpecificationAgent` | Opus 4.7 | `Agent, HasTools` | IA de interação — gera spec técnica a partir do PRD |
| `QuotationAgent` | Opus 4.7 | `Agent, HasStructuredOutput, HasTools` | IA de interação — estima custo e ROI |

**Regra:** `HasStructuredOutput` é obrigatório em agentes que retornam JSON estruturado (OrchestratorAgent, QAAuditorAgent, QuotationAgent, SecuritySpecialist, PerformanceAnalyst). O SDK valida o schema na saída — sem parsing manual.

---

### Módulo C — Ferramentas Atômicas (Tool Layer)

Residem em `app/Ai/Tools/`. Todas implementam `Laravel\Ai\Contracts\Tool`.

| Tool | Escopo | Propósito |
|---|---|---|
| `BoostTool` | `local_path` do Projeto Alvo | Envelopa `php artisan boost:*` do Projeto Alvo (database-schema, search-docs, database-query, browser-logs) |
| `FileReadTool` | `local_path` do Projeto Alvo | Leitura de arquivos (path, limite de linhas) |
| `FileWriteTool` | `local_path` do Projeto Alvo | Escrita (`action: create/replace/append`); **`action=patch` não existe** |
| `GitOperationTool` | `local_path` do Projeto Alvo | Git (status, diff, add, commit, push, branch, checkout, revert) |
| `ShellExecuteTool` | `local_path` do Projeto Alvo | Shell com allowlist de binários (`php`, `composer`, `git`, `npm`, `phpstan`, `enlightn`, `nikto`) |
| `DocSearchTool` | `local_path` do Projeto Alvo | Busca semântica em docs via Boost; Fase 3: fallback web (DuckDuckGo/Firecrawl) |

**Regra crítica:** `BoostTool` deve ser instanciado com o `local_path` do Projeto Alvo (não do ai-dev-core). **Status atual:** BoostTool opera no contexto do ai-dev-core — tornar project-path-aware é item pendente de Fase 1.

**Hardening `database-query` (Fase 2):** Migrar de SQL raw para schema estruturado com allowlist de tabelas/colunas/operadores, redação de campos `_token/_secret/_password/_key/_hash`, conexão `readonly`, cap de **5.000 chars** de output.

---

### Módulo D — Pipeline de Jobs (Filas Horizon)

Todos os Jobs residem em `app/Jobs/`. Enfileirados via Laravel Queue + Redis + Horizon.

| Job | Fila | Propósito |
|---|---|---|
| `OrchestratorJob` | `orchestrator` | Despacha `OrchestratorAgent`, grava subtasks, enfileira `ProcessSubtaskJob` por execution_order |
| `ProcessSubtaskJob` | `subtasks` | Executa `SpecialistAgent` para uma Subtask específica |
| `QAAuditJob` | `qa` | Executa `QAAuditorAgent` sobre o diff da subtask |
| `SecurityAuditJob` | `security` | Executa `SecuritySpecialist` após QA aprovar |
| `PerformanceAnalysisJob` | `performance` | Executa `PerformanceAnalyst` após Security aprovar |

**Decisão de arquitetura (Horizon vs. `Concurrency::run()`):** O sistema usa Horizon (Queue-based) em vez de `Concurrency::run()` para garantir durabilidade entre reinicios de servidor. Ver `MIGRATION_LARAVEL13.md §3.6`.

---

### Módulo E — Admin Panel (Filament v5)

Reside em `app/Filament/`. Interface humana do ai-dev-core.

#### Resources (Fase 2):

| Resource | Propósito |
|---|---|
| `ProjectResource` | CRUD de Projetos Alvo (local_path, repo, status) |
| `TaskResource` | CRUD de Tasks com PRD editor, status pipeline, métricas |
| `AgentConfigResource` | Configuração dinâmica de cada agente (modelo, temperatura, prompt) |

#### Dashboard (Fase 2):

- Widget de Tasks Ativas (status, agente alocado, progresso)
- Widget de Custo (tokens consumidos, custo USD por task/projeto)
- Widget de Saúde dos Workers (filas Horizon ativas, jobs falhos)
- Widget de Últimas Subtasks (resultado, diff, QA score)

#### IAs de Interação (Fase 1 — integradas ao Admin Panel):

| Agente | Trigger | Resultado |
|---|---|---|
| `RefineDescriptionAgent` | Botão "Refinar" ao criar task | Texto da descrição refinado para precisão técnica |
| `SpecificationAgent` | Aprovação da descrição | Spec técnica completa gerada em `project_specifications` |
| `QuotationAgent` | Aprovação da spec | Orçamento com custo humano vs. AI-Dev e ROI em `project_quotations` |

---

### Módulo F — Segurança e Auditoria

#### F.1. PostgreSQL Read-Only por Projeto Alvo (Fase 2):

Provisionar usuário `aidev_readonly` com `GRANT SELECT` em cada banco de Projeto Alvo. Configurar conexão `readonly` em `config/database.php` para `BoostTool.database-query`.

#### F.2. Listener `Tool::dispatched()` (Fase 2 — Alta Prioridade):

```php
// AppServiceProvider::boot()
Tool::dispatched(function (string $toolName, array $input, mixed $output) {
    ToolCallsLog::create([...]);
});
```

Registrar em `AppServiceProvider` para popular `tool_calls_log` em **toda** tool call.

#### F.3. `ShellExecuteTool` Binary Allowlist (Fase 2):

Binários permitidos: `php`, `composer`, `git`, `npm`, `phpstan`, `phpstan`, `enlightn`, `nikto`, `composer audit`. Qualquer binário fora da lista deve lançar exception.

#### F.4. Circuit Breakers (Fase 2):

- Limite de custo por task (configurável em `agents_config`)
- Limite de retries (`tasks.max_retries`, default: 3)
- Timeout por job (configurável por fila no Horizon)
- Escalada para Human-in-the-Loop ao atingir `max_retries`

---

### Módulo G — Sentinela (Self-Healing Runtime — Fase 2)

Exception Handler customizado instalado em cada **Projeto Alvo**. Quando detecta exceção em runtime:

1. Captura stack trace completo
2. Cria automaticamente uma task no ai-dev-core com PRD preenchido (título `[SENTINEL]`, `priority_hint: critical`, `source: sentinel`)
3. Preenche `context.error_stack_trace`, `context.related_files` e `context.related_tables` a partir do trace
4. Insere task com prioridade máxima (100) na fila

---

### Módulo H — Git Branching e FileLockManager (Fase 2)

- Cada task recebe um branch dedicado: `task/{task_uuid_short}` (registrado em `tasks.git_branch`)
- `FileLockManager` (mutex) previne conflito de escrita entre subtasks paralelas
- `tasks.commit_hash` registra o hash final para rollback via `git revert <hash>`
- Subtasks registram `subtasks.commit_hash` para rollback preciso por subtask

---

### Módulo I — RAG e Memória Vetorial (Fase 3)

#### I.1. `problems_solutions` (pgvector):

Migration com coluna `vector(1536)`. Toda resolução de erro via Sentinela alimenta automaticamente esta tabela via `ProblemSolutionRecorder` (Listener).

#### I.2. `SimilaritySearch::usingModel()`:

Tool nativa do SDK (`SimilaritySearch::usingModel(ProblemSolution::class, 'embedding')->minSimilarity(0.7)`). Registrada dinamicamente nos agentes — o agente decide quando usar, não é injeção estática obrigatória.

#### I.3. Context Compressor (Ollama Local):

`qwen2.5:0.5b` local via Ollama. Comprime contexto quando janela atinge 60%. Zero custo de API.

#### I.4. Prompt Caching:

Ordem obrigatória: instrução estática → docs semi-estáticas → contexto dinâmico do PRD.

---

## 4. Fases de Implementação

### Fase 1 — Core Loop (MVP Mínimo Funcional)

**Objetivo:** Ciclo completo funcional: Task → OrchestratorAgent → SpecialistAgent → QAAuditorAgent → Commit.

**Critérios de Aceite da Fase 1:**

- [ ] Migrations criadas e rodando para: `projects`, `tasks`, `subtasks`, `agents_config`, `task_transitions`, `project_specifications`, `project_modules`, `project_quotations`, `agent_conversations`, `agent_conversation_messages`
- [ ] Models com Enums e validação de transições de estado (transição inválida lança exception)
- [ ] `OrchestratorAgent` (Opus 4.7): implementa `Agent, HasStructuredOutput, HasTools`; quebra PRD em Sub-PRDs; retorna JSON com array de subtasks
- [ ] `QAAuditorAgent` (Sonnet 4.6): implementa `Agent, HasStructuredOutput, HasTools`; retorna JSON canônico com `approved`, `criteria_checklist`, `issues`, `overall_quality`, `recommendation`
- [ ] Pelo menos 1 `SpecialistAgent` funcional (BackendSpecialist) com as 6 tools injetadas
- [ ] 6 Tools implementando `Laravel\Ai\Contracts\Tool`: `BoostTool`, `DocSearchTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool`, `ShellExecuteTool`
- [ ] **BoostTool project-path-aware**: recebe `local_path` do Projeto Alvo no constructor e roteia `php artisan boost:*` para aquele path
- [ ] `OrchestratorJob`, `ProcessSubtaskJob`, `QAAuditJob` implementados e enfileiráveis
- [ ] Horizon v5 configurado com 4 filas: `orchestrator`, `subtasks`, `qa`, `default`
- [ ] IAs de Interação funcionando no Admin Panel: `RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent`
- [ ] `PRDValidator.php` implementado e chamado antes de aceitar task
- [ ] Teste end-to-end documentado: task "Criar Model de Post" executada de ponta a ponta sem intervenção humana
- [ ] Nenhum agente opera fora do `local_path` do Projeto Alvo

---

### Fase 2 — Qualidade, Segurança e Observabilidade

**Objetivo:** Camadas de segurança, auditoria completa e interface de gestão operacional.

**Critérios de Aceite da Fase 2:**

- [ ] **[Alta]** `HasStructuredOutput` implementado em: `OrchestratorAgent`, `QAAuditorAgent`, `QuotationAgent`, `SecuritySpecialist`, `PerformanceAnalyst`
- [ ] **[Alta]** Listener `Tool::dispatched()` em `AppServiceProvider` populando `tool_calls_log` em toda tool call
- [ ] **[Alta]** `BoostTool.database-query` hardened: schema estruturado (`table/columns/where` com allowlist), redação de `_token/_secret/_password/_key/_hash`, conexão `readonly`, cap 5.000 chars
- [ ] Migrations criadas e rodando para: `agent_executions`, `tool_calls_log`, `webhooks_config`
- [ ] Filament Resources: `ProjectResource`, `TaskResource`, `AgentConfigResource` (listagem paginada, formulários, filtros)
- [ ] Dashboard Filament com widgets: Tasks Ativas, Custo por Projeto, Saúde dos Workers, Últimas Subtasks
- [ ] `SecurityAuditJob` + `SecuritySpecialist` implementados (Enlightn, Nikto, `composer audit`)
- [ ] `PerformanceAnalysisJob` + `PerformanceAnalyst` implementados (Pest 4 browser tests: `visit()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`)
- [ ] Sentinela instalado em Projetos Alvo e gerando tasks automaticamente no ai-dev-core
- [ ] `FileLockManager` implementado; subtasks paralelas não escrevem no mesmo arquivo simultaneamente
- [ ] Git branching por task (`tasks.git_branch = task/{uuid_short}`), commit hash registrado
- [ ] `ShellExecuteTool` com allowlist de binários; tentativa de binário não permitido lança exception auditada
- [ ] Circuit breakers: limite de custo por task, `max_retries` respeitado, escalada para Human-in-the-Loop
- [ ] PostgreSQL read-only user provisionado por Projeto Alvo (script de provisionamento documentado)
- [ ] `TaskOrchestrator.php` (Service) coordenando o pipeline Agent→QA→Security→Performance→Git
- [ ] `PRDValidator.php` com todas as validações (campos obrigatórios, enums, path prefix, dedup)

---

### Fase 3 — Inteligência, Memória e Expansão

**Objetivo:** Memória vetorial, auto-evolução, compressão de contexto e publicação em redes sociais.

**Critérios de Aceite da Fase 3:**

- [ ] Migration `problems_solutions` com coluna `vector(1536)` via pgvector
- [ ] `ProblemSolutionRecorder` (Listener no Sentinela): alimenta `problems_solutions` automaticamente após resolução
- [ ] `SimilaritySearch::usingModel(ProblemSolution::class, 'embedding')->minSimilarity(0.7)` registrado como Tool SDK dinâmica nos agentes
- [ ] `ContextCompressor` Agent (Ollama `qwen2.5:0.5b`) + `ContextCompressionJob` funcional
- [ ] `toEmbeddings()` via SDK para vetorizar pares problema/solução
- [ ] Prompt Caching com ordem correta: estático → semi-estático → dinâmico
- [ ] `DocSearchTool` com fallback web (DuckDuckGo / Firecrawl self-hosted) quando Boost não tiver a doc
- [ ] `context_library` migration + seed inicial com padrões TALL obrigatórios
- [ ] Webhooks de entrada (GitHub, CI/CD) recebendo e convertendo em tasks automaticamente
- [ ] `SocialPostingAgent` (Haiku 4.5) + `social_accounts` (Filament Resource) + integração `hamzahassanm/laravel-social-auto-post`
- [ ] OWASP ZAP em modo headless para scan profundo (complementar ao Nikto do Fase 2)

---

## 5. Restrições Técnicas (Invioláveis)

1. **Stack exclusiva TALL:** Tailwind + Alpine.js + Laravel + Livewire. Proibido React, Vue, Inertia.js ou qualquer SPA framework.
2. **Sem Blade manual em formulários:** usar exclusivamente FormBuilder do Filament v5.
3. **Proibido DB::raw() sem justificativa:** usar Eloquent. SQL raw só em otimizações documentadas.
4. **Sem pacotes npm/composer extras** sem avaliação de segurança e aprovação explícita.
5. **Agentes só escrevem dentro do `local_path` do Projeto Alvo.** Nunca no filesystem do ai-dev-core.
6. **`FileWriteTool` action válidas:** `create`, `replace`, `append`. Não existe `action=patch`.
7. **Dusk removido** dos dois lados (ai-dev-core e Projetos Alvo). Testes de browser usam **Pest v4**: `visit()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`.
8. **PRD obrigatório** para toda task. `PRDValidator.php` rejeita tasks sem os campos obrigatórios.
9. **`HasStructuredOutput`** obrigatório em todo agente que retorna JSON estruturado. Proibido parsing manual de JSON de saída.
10. **Cap de 5.000 chars** no output do `BoostTool.database-query`.
11. **Horizon** (não `Concurrency::run()`) para paralelização de subtasks. Garante durabilidade entre reinicios.

---

## 6. Restrições de Modelos LLM

| Situação | Modelo Permitido |
|---|---|
| Planejamento complexo (OrchestratorAgent, SpecificationAgent, QuotationAgent, RefineDescriptionAgent) | `anthropic/claude-opus-4.7` via OpenRouter |
| Código, QA, especialistas, segurança, performance | `anthropic/claude-sonnet-4-6` via OpenRouter |
| Documentação, buscas, tarefas simples (DocsAgent) | `anthropic/claude-haiku-4-5-20251001` via OpenRouter |
| Compressão de contexto, embeddings (Fase 3) | Ollama local — `qwen2.5:0.5b` |

---

## 7. URLs de Referência Técnica

| Recurso | URL |
|---|---|
| Laravel AI SDK — Multi-Agent Workflows | https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk |
| Laravel AI SDK — Production-Safe Database Tools | https://laravel.com/blog/laravel-ai-sdk-building-production-safe-database-tools-for-agents |
| Laravel AI SDK Docs | https://laravel.com/docs/ai |
| Filament v5 Docs | https://filamentphp.com/docs/ |
| Laravel 13 Docs | https://laravel.com/docs/13.x |
| Pest v4 Docs | https://pestphp.com/docs |
| Laravel Horizon Docs | https://laravel.com/docs/horizon |
| Laravel Boost Docs | https://laravel.com/docs/boost |

---

## 8. Tabelas do ai-dev-core (Referência Canônica)

### Tabelas do Core Master (injetadas em todos os Projetos, incluindo ai-dev-core):

| Tabela | Propósito |
|---|---|
| `audit_logs` | Log global de todas as ações (Insert/Update/Delete) |
| `roles` e `permissions` | Perfis e permissões granulares por módulo |
| `system_settings` | Configurações do sistema via UI (evita hardcoding no `.env`) |
| `users` | Cadastro central de usuários |

### Tabelas operacionais do ai-dev-core:

| Tabela | Propósito |
|---|---|
| `projects` | Projetos Alvo (repo, `local_path`, status) |
| `project_specifications` | Specs técnicas geradas pelo `SpecificationAgent` |
| `project_modules` | Módulos/submódulos hierárquicos com PRD por módulo |
| `project_quotations` | Orçamentos com ROI |
| `tasks` | Tasks com PRD JSON e máquina de estados |
| `subtasks` | Decomposição granular (Sub-PRD por especialista) |
| `agents_config` | Configuração dinâmica dos agentes |
| `task_transitions` | Log de auditoria de transições |
| `agent_conversations` | Conversas SDK (`RemembersConversations`) |
| `agent_conversation_messages` | Mensagens SDK |
| `social_accounts` | Credenciais de redes sociais (Fase 3) |
| `agent_executions` | Log LLM por chamada — tokens, custo (Fase 2) |
| `tool_calls_log` | Auditoria de cada tool call (Fase 2) |
| `webhooks_config` | Webhooks de entrada (Fase 2) |
| `context_library` | Padrões TALL ("Bíblia") — few-shot fixo (Fase 3) |
| `problems_solutions` | RAG vetorial — pares erro/solução com `vector(1536)` (Fase 3) |

---

## 9. Diretrizes de Qualidade

- Todo código gerado **deve passar** em `php artisan test` sem falhas antes do commit
- Todo código gerado **deve passar** em `phpstan --level=8` sem erros
- Nenhuma task é marcada `completed` sem aprovação do `QAAuditorAgent` com `approved: true`
- Nenhuma subtask commita em `main` — sempre em `task/{uuid_short}`, merge após aprovação total
- `OrchestratorAgent` deve registrar `execution_order` e `dependencies` em toda subtask para controle de paralelização via Horizon
- QA Auditor retorna JSON canônico:
  ```json
  {
    "approved": true,
    "criteria_checklist": [{"criterion": "...", "passed": true, "note": "..."}],
    "issues": [{"file": "...", "line": 0, "severity": "critical|minor|cosmetic", "description": "...", "suggestion": "..."}],
    "overall_quality": "excellent|good|acceptable|poor",
    "recommendation": "approve|fix_and_retry|escalate_to_human"
  }
  ```

---

## 10. Estado Atual do Projeto (Referência para a Refatoração)

> **Fonte:** `MIGRATION_LARAVEL13.md` — migração Laravel 13 concluída.

**Concluído:**
- Laravel 13 + Laravel AI SDK v0.5.1 + Filament v5 + PostgreSQL 16 + pgvector + Redis 7 + Horizon v5 + Pest v4 instalados e operacionais
- Migrations das tabelas operacionais existem e foram rodadas
- Estrutura de diretórios `app/Ai/Agents/` e `app/Ai/Tools/` criada

**Pendente (foco da refatoração):**
- `BoostTool` não é project-path-aware (opera no contexto do ai-dev-core)
- `HasStructuredOutput` ausente em OrchestratorAgent, QAAuditorAgent, QuotationAgent
- `Tool::dispatched()` listener não implementado
- `BoostTool.database-query` ainda usa SQL raw sem hardening
- Filament Resources para Projects, Tasks, AgentConfig não implementados
- SecuritySpecialist, PerformanceAnalyst, SecurityAuditJob, PerformanceAnalysisJob não implementados
- FileLockManager não implementado
- PRDValidator não implementado
- Sentinela não implementado
- Git branching por task não implementado
- Teste end-to-end completo não executado
