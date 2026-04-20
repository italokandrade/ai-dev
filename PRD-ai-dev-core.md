# PRD — ai-dev-core (Plataforma Master de Desenvolvimento Autônomo)

> **Versão:** 2.0 — 2026-04-20
> **Escopo:** Refatoração completa do ai-dev-core. Documento escrito com base na inspeção direta do código atual.
> **Referências:** `README.md`, `ARCHITECTURE.md`, `FERRAMENTAS.md`, `PROMPTS.md`, `INFRASTRUCTURE.md`, `PRD_SCHEMA.md`, `ADMIN_GUIDE.md`
>
> **Legenda de status:**
> - ✅ Implementado e alinhado com o projeto
> - ⚠️ Existe mas precisa de refatoração
> - ❌ Não implementado — deve ser criado

---

## 1. Visão Geral

O **ai-dev-core** é uma aplicação Laravel 13 cuja missão é orquestrar o ciclo completo de desenvolvimento autônomo (planejamento → codificação → QA → segurança → performance → commit) de outras aplicações Laravel (**Projetos Alvo**). Todo código escrito pelos agentes vai para o filesystem do Projeto Alvo. O ai-dev-core nunca modifica a si próprio via agentes.

**Stack obrigatória (não negociável):**

| Camada | Tecnologia | Status |
|---|---|---|
| Backend | Laravel 13.5 + PHP 8.3 | ✅ |
| Frontend | Livewire 4 + Alpine.js v3 + Tailwind CSS v4 | ✅ |
| Admin Panel | Filament v5.5 | ✅ |
| Banco Relacional | PostgreSQL 16 + pgvector | ✅ |
| Filas/Cache | Redis 7 + Laravel Horizon v5 | ✅ |
| AI SDK | `laravel/ai` v0.5 | ✅ |
| Boost | `laravel/boost` v2.4 | ✅ |
| LLM Planejamento | OpenRouter → `anthropic/claude-opus-4.7` | ✅ |
| LLM Código/QA | OpenRouter → `anthropic/claude-sonnet-4-6` | ✅ |
| LLM Docs | OpenRouter → `anthropic/claude-haiku-4-5-20251001` | ✅ |
| Testes | Pest v4 + PHPUnit v12 | ✅ |
| Codebase path | `/var/www/html/projetos/ai-dev/ai-dev-core` | ✅ |

---

## 2. Estado Atual — Diagnóstico por Módulo

---

### Módulo A — Banco de Dados

#### A.1. Migrations existentes ✅

Todas as migrations de Fase 1 existem e foram executadas:

| Tabela | Status |
|---|---|
| `projects` | ✅ |
| `agents_config` | ✅ |
| `tasks` | ✅ |
| `subtasks` | ✅ |
| `task_transitions` | ✅ |
| `agent_conversations` | ✅ (gerenciada pelo SDK) |
| `project_specifications` | ✅ |
| `project_modules` | ✅ (com parent_id hierárquico) |
| `project_quotations` | ✅ |
| `social_accounts` | ✅ (migration criada, integração pendente Fase 3) |
| `system_settings` | ✅ |

#### A.2. Migrations pendentes

| Tabela | Fase | Status |
|---|---|---|
| `agent_executions` | 2 | ❌ |
| `tool_calls_log` | 2 | ❌ |
| `webhooks_config` | 2 | ❌ |
| `context_library` | 3 | ❌ |
| `problems_solutions` | 3 | ❌ (precisa de coluna `vector(1536)` via pgvector) |

#### A.3. Enums

✅ Implementados e completos: `TaskStatus`, `SubtaskStatus`, `Priority`, `TaskSource`, `AgentProvider`, `KnowledgeArea`, `ModuleStatus`, `SecuritySeverity`, `StackComponent`, `ExecutionStatus`, `ToolCallStatus`.

#### A.4. Máquina de Estados

✅ `TaskStatus::canTransitionTo()` e `SubtaskStatus::canTransitionTo()` implementados com `allowedTransitions()`. Transições inválidas lançam `InvalidArgumentException`. `TaskTransition` é gravado em toda transição.

**Máquina Task:**
```
pending → in_progress → qa_audit → testing → completed
                     ↘ rollback → failed | pending (retry)
           qa_audit → rejected → in_progress | escalated
```

**Máquina Subtask:**
```
pending → running → qa_audit → success
        ↘ error → pending (retry)
pending → blocked → pending
```

---

### Módulo B — Agentes de Desenvolvimento

#### B.1. OrchestratorAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider `openrouter`, modelo `claude-opus-4.7`. Decompõe PRD em Sub-PRDs retornando array JSON.

**O que falta / precisa refatorar:**
- ❌ Não implementa `HasStructuredOutput` — saída JSON é parseada manualmente com `json_decode()` e `preg_replace` para remover markdown fences. Risco de falha silenciosa de formato.
- ❌ Não implementa `HasTools` — o PRD arquitetural exige ferramentas para leitura de contexto.
- ⚠️ O `OrchestratorJob` chama `prompt(..., provider: 'orchestrator_chain')` mas esse provider não existe em `config/ai.php`. Apenas `openrouter_chain` existe. **Isso é um bug ativo.**

**Refatoração necessária (Fase 1):**
1. Implementar `HasStructuredOutput` com schema do array de Sub-PRDs.
2. Corrigir o provider: renomear `orchestrator_chain` → `openrouter_chain` no `OrchestratorJob`, ou adicionar o provider ao `config/ai.php`.

---

#### B.2. QAAuditorAgent ⚠️

**O que existe:** Implementa `Agent, HasTools` com `BoostTool`. Provider `openrouter`, modelo `claude-sonnet-4-6`. Retorna JSON de auditoria.

**O que falta / precisa refatorar:**
- ❌ Não implementa `HasStructuredOutput` — parse manual de JSON com `preg_replace` e `json_decode`. O `QAAuditJob` auto-aprova em caso de falha de parse (comportamento perigoso).
- ⚠️ Schema de retorno atual é simplificado (`approved`, `overall_quality`, `issues`, `summary`). O schema canônico do PRD exige: `criteria_checklist`, `recommendation`, `issues` com `file/line/severity/description/suggestion`.

**Refatoração necessária (Fase 2 — alta prioridade):**
1. Implementar `HasStructuredOutput` com schema canônico completo.
2. Eliminar o auto-approve em caso de falha de parse — deve falhar explicitamente.
3. Atualizar `instructions()` para exigir o schema canônico.

---

#### B.3. SpecialistAgent ✅⚠️

**O que existe:** Implementa `Agent, HasTools`. Recebe `$projectPath` no constructor. Injeta as 6 tools escopadas ao `projectPath`. Provider `openrouter`, modelo `claude-sonnet-4-6`.

**Observação de design:** O PRD arquitetural listava classes separadas por especialidade (BackendSpecialist, FrontendSpecialist, etc.). O código implementou um `SpecialistAgent` genérico que recebe `assigned_agent` como string. Esta é uma decisão válida — agente genérico + specialization via prompt é mais simples e funcional. O PRD passa a documentar este design.

**O que falta:**
- ⚠️ O `SubagentJob` passa `provider: 'specialist_chain'` ao chamar `prompt()`. Este provider não existe em `config/ai.php`. **Bug ativo.**
- ⚠️ `instructions()` não diferenciam o tipo de especialista (`assigned_agent`). O agente deveria adaptar seu comportamento ao tipo recebido.
- ❌ Agente não recebe `assigned_agent` do subtask para adaptar o prompt — recebe apenas `$projectPath`.

**Refatoração necessária (Fase 1):**
1. Corrigir provider `specialist_chain` → `openrouter_chain` (ou criar o provider).
2. Fazer `SpecialistAgent` receber o `assigned_agent` slug no constructor e usar no `instructions()`.

---

#### B.4. RefineDescriptionAgent ✅

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider `openrouter`, modelo `claude-opus-4.7`. Usa `SystemContextService` para contexto dinâmico da stack. Sem `HasTools`.

**Status:** Alinhado com o projeto. Sem refatoração necessária no momento.

---

#### B.5. SpecificationAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider `openrouter`, modelo `claude-opus-4.7`. Retorna JSON de especificação técnica.

**O que falta:**
- ❌ Não implementa `HasStructuredOutput` — parse manual no `GenerateProjectSpecificationJob`.
- ❌ Não tem `HasTools`.

**Refatoração necessária (Fase 2):**
1. Implementar `HasStructuredOutput` com schema da especificação.

---

#### B.6. QuotationAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider `openrouter`, modelo `claude-opus-4.7`. Retorna JSON de orçamento.

**O que falta:**
- ❌ Não implementa `HasStructuredOutput` — parse manual no `GenerateProjectQuotationJob`.
- ❌ Não tem `HasTools`.

**Refatoração necessária (Fase 2 — alta prioridade):**
1. Implementar `HasStructuredOutput` com schema de orçamento (horas por área, custo humano vs. AI-Dev, ROI).

---

#### B.7. DocsAgent ✅

**O que existe:** Implementa `Agent, HasTools` com `BoostTool`. Provider `openrouter`, modelo `claude-haiku-4-5-20251001`. Busca documentação via `search-docs`.

**Status:** Alinhado. Sem refatoração necessária no momento.

---

#### B.8. SecuritySpecialist ❌

Não existe. Deve ser criado na Fase 2. Implementará `Agent, HasStructuredOutput, HasTools`. Modelo `claude-sonnet-4-6`. Rodará Enlightn, Nikto, `composer audit`. Retornará JSON estruturado com vulnerabilidades encontradas.

---

#### B.9. PerformanceAnalyst ❌

Não existe. Deve ser criado na Fase 2. Implementará `Agent, HasStructuredOutput, HasTools`. Modelo `claude-sonnet-4-6`. Rodará Pest 4 browser tests (`visit()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`). Retornará JSON estruturado com métricas de performance.

---

### Módulo C — Ferramentas Atômicas (Tool Layer)

#### C.1. BoostTool ⚠️ CRÍTICO

**O que existe:** Implementa `Tool`. Mapeia sub-tools do Boost (`search-docs`, `database-schema`, `database-query`, `browser-logs`, `last-error`). Instancia as classes Boost via `app()`.

**Problemas críticos:**
- ❌ **Não é project-path-aware.** Não tem `workingDirectory` no constructor. As classes Boost são instanciadas via `app()` e operam no contexto do ai-dev-core, **não do Projeto Alvo**. Isso é a falha mais crítica do sistema: o agente lê o schema do banco errado.
- ❌ `BoostTool` não é instanciado com `local_path` no `SpecialistAgent`. Atualmente o `SpecialistAgent` passa `new BoostTool` sem argumentos — quando deveria passar `new BoostTool($this->projectPath)`.
- ❌ `DocsAgent` e `QAAuditorAgent` também usam `new BoostTool` sem path.
- ❌ Schema do `database-query` usa SQL raw — sem allowlist, sem redação de campos sensíveis, sem conexão readonly, sem cap de 5.000 chars.

**Refatoração necessária (Fase 1 — bloqueante):**
1. Adicionar `__construct(private readonly ?string $workingDirectory = null)`.
2. Rotear todas as sub-tools para o `workingDirectory` recebido (executar `php artisan boost:*` dentro do `local_path`).
3. Atualizar todos os agentes que instanciam `BoostTool` para passar o `projectPath`.

**Refatoração necessária (Fase 2 — alta prioridade):**
4. Hardening do `database-query`: allowlist de tabelas/colunas/operadores, redação de campos `_token/_secret/_password/_key/_hash`, conexão `readonly`, cap de 5.000 chars.

---

#### C.2. FileReadTool ✅

**O que existe:** Implementa `Tool`. Recebe `$workingDirectory` no constructor. Suporta leitura de arquivo com offset/limit e listagem de diretório. Escopad ao `projectPath`.

**Status:** Alinhado. Sem refatoração necessária.

---

#### C.3. FileWriteTool ✅

**O que existe:** Implementa `Tool`. Recebe `$workingDirectory` no constructor. Actions: `write` (criar/sobrescrever), `replace` (find & replace único), `mkdir`. Escopo ao `projectPath`.

**Status:** Alinhado. `action=replace` é o correto per PRD. `action=patch` não existe. Sem refatoração necessária.

---

#### C.4. GitOperationTool ✅

**O que existe:** Implementa `Tool`. Recebe `$workingDirectory` no constructor. Actions: `status`, `diff`, `log`, `branch_create`, `branch_checkout`, `branch_list`, `add`, `commit`, `push`, `reset_hard`, `stash`. Escopado ao `projectPath`.

**Status:** Alinhado. Falta `revert` para rollback preciso por commit hash — adicionar na Fase 2.

---

#### C.5. ShellExecuteTool ⚠️

**O que existe:** Implementa `Tool`. Recebe `$workingDirectory` no constructor. Tem lista negra (`BLOCKED_PATTERNS`) de comandos perigosos.

**O que falta:**
- ❌ Não tem **allowlist de binários** — o PRD exige allowlist explícita (`php`, `composer`, `git`, `npm`, `phpstan`, `enlightn`, `nikto`, `composer audit`). A lista negra atual apenas bloqueia padrões específicos como `rm -rf /`, mas qualquer outro binário passa.

**Refatoração necessária (Fase 2):**
1. Substituir `BLOCKED_PATTERNS` por allowlist de binários explícita.
2. Binário fora da allowlist → lançar exception auditada.

---

#### C.6. DocSearchTool ⚠️

**O que existe:** Implementa `Tool`. Delega para `DocsAgent` que usa `BoostTool.search-docs` internamente.

**O que falta:**
- ❌ Não é project-path-aware. Não recebe `workingDirectory` e não passa path para o `DocsAgent`/`BoostTool` interno. Após o fix do `BoostTool` (C.1), este tool também precisará receber o path.

**Refatoração necessária (Fase 1, junto com BoostTool):**
1. Adicionar `__construct(private readonly string $workingDirectory)`.
2. Passar `workingDirectory` ao instanciar `BoostTool` internamente.

---

### Módulo D — Pipeline de Jobs

#### D.1. OrchestratorJob ⚠️

**O que existe:** Implementa `ShouldQueue`. Fila `orchestrator`. Transição `pending → in_progress`, chama `OrchestratorAgent`, cria subtasks, despacha subtasks prontas via `SubagentJob`.

**Problemas:**
- ⚠️ Passa `provider: 'orchestrator_chain'` ao chamar `OrchestratorAgent::make()->prompt()`. Provider inexistente em `config/ai.php`. **Bug ativo — vai falhar em produção.**
- ⚠️ Após `HasStructuredOutput` ser implementado no `OrchestratorAgent`, o parse manual de JSON neste Job deve ser removido.
- ❌ Não cria branch git por task (`task/{uuid_short}`) antes de despachar subtasks. Git branching por task é Fase 2.

**Refatoração necessária (Fase 1):**
1. Corrigir `provider` para `openrouter_chain` (ou criar `orchestrator_chain` em `config/ai.php`).
2. Remover parse manual após implementar `HasStructuredOutput`.

---

#### D.2. SubagentJob ⚠️

**O que existe:** Implementa `ShouldQueue`. Fila `agents`. Instancia `SpecialistAgent($workDir)`, chama `prompt()`, captura diff, despacha `QAAuditJob`.

**Problemas:**
- ⚠️ **Nome diverge do PRD**: o PRD define `ProcessSubtaskJob`, o código chama `SubagentJob`. Deve ser renomeado para consistência.
- ⚠️ **Fila diverge**: usa fila `agents`, mas o Horizon está configurado com supervisor para fila `subagent`. **Bug de roteamento de fila.**
- ⚠️ Passa `provider: 'specialist_chain'` ao chamar `SpecialistAgent`. Provider inexistente. **Bug ativo.**
- ❌ Não passa `assigned_agent` ao instanciar `SpecialistAgent` — agente não sabe qual especialidade aplicar.

**Refatoração necessária (Fase 1):**
1. Renomear para `ProcessSubtaskJob`.
2. Corrigir fila para `subtasks` (e atualizar Horizon).
3. Corrigir `provider` para `openrouter_chain`.
4. Passar `assigned_agent` ao construtor do `SpecialistAgent`.

---

#### D.3. QAAuditJob ✅⚠️

**O que existe:** Implementa `ShouldQueue`. Fila `qa`. Chama `QAAuditorAgent`, parse de JSON, aprova/rejeita subtask, commit com hash, despacha próximas subtasks ou conclui task.

**Problemas:**
- ⚠️ Auto-approve em caso de falha de parse (`json_decode` falha → aprova automaticamente). Comportamento perigoso.
- ⚠️ Após `HasStructuredOutput` no `QAAuditorAgent`, o parse manual deve ser removido.
- ❌ Após aprovação, não despacha `SecurityAuditJob` (Fase 2).

**Refatoração necessária (Fase 2):**
1. Remover auto-approve — falha de parse deve rejeitar a subtask.
2. Remover parse manual após `HasStructuredOutput`.
3. Após todas subtasks aprovadas, despachar `SecurityAuditJob` em vez de ir direto para `completed`.

---

#### D.4. SecurityAuditJob ❌

Não existe. Deve ser criado na Fase 2. Fila `security`. Executa `SecuritySpecialist`. Após aprovação, despacha `PerformanceAnalysisJob`.

---

#### D.5. PerformanceAnalysisJob ❌

Não existe. Deve ser criado na Fase 2. Fila `performance`. Executa `PerformanceAnalyst`. Após aprovação, conclui a task (`→ completed`).

---

#### D.6. Jobs Auxiliares existentes (fora do pipeline principal)

Os jobs abaixo existem e são usados pelo Admin Panel. Não estavam no PRD original mas fazem parte do sistema:

| Job | Fila | Propósito |
|---|---|---|
| `GenerateProjectSpecificationJob` | `orchestrator` | Gera spec técnica via `SpecificationAgent` |
| `GenerateProjectQuotationJob` | `orchestrator` | Gera orçamento via `QuotationAgent` |
| `GenerateTasksFromSpecJob` | `orchestrator` | Cria tasks a partir de spec aprovada |
| `ScaffoldProjectJob` | `orchestrator` | Scaffolding inicial do Projeto Alvo |

**Status:** Existem e funcionam. Devem ser documentados e mantidos.

---

### Módulo E — Admin Panel (Filament v5)

#### E.1. Resources ✅

Todos já implementados — o PRD dizia que eram Fase 2, mas já existem:

| Resource | Status |
|---|---|
| `ProjectResource` | ✅ Implementado com form, table, view, IAs de interação integradas |
| `TaskResource` | ✅ Implementado com form, table, view, SubtasksRelationManager |
| `AgentConfigResource` | ✅ Implementado |
| `ProjectSpecificationResource` | ✅ Implementado (extra) |
| `ProjectModuleResource` | ✅ Implementado com TasksRelationManager (extra) |
| `ProjectQuotationResource` | ✅ Implementado (extra) |
| `SocialAccountResource` | ✅ Migration criada, resource implementado (integração Fase 3) |

#### E.2. Widgets de Dashboard ✅

Todos já implementados:

| Widget | Status |
|---|---|
| `DevelopmentStatusWidget` | ✅ |
| `StatsOverviewWidget` | ✅ |
| `TaskBoardWidget` | ✅ |
| `ProjectRoadmapWidget` | ✅ |
| `AgentHealthWidget` | ✅ |

#### E.3. IAs de Interação no Admin Panel ✅⚠️

- ✅ `RefineDescriptionAgent` integrado no `ProjectResource` — funcional.
- ⚠️ `SpecificationAgent` e `QuotationAgent` aguardam `HasStructuredOutput` para eliminar parse manual nos Jobs.

---

### Módulo F — Segurança e Auditoria

#### F.1. Tool::dispatched() Listener ❌

`AppServiceProvider::boot()` registra apenas o `failover` provider. Nenhum listener `Tool::dispatched()` existe. A tabela `tool_calls_log` não existe e não será populada.

**Necessário (Fase 2 — alta prioridade):**
1. Criar migration `tool_calls_log`.
2. Criar Model `ToolCallLog`.
3. Registrar listener em `AppServiceProvider::boot()`.

---

#### F.2. ShellExecuteTool Binary Allowlist ❌

Ver C.5. Atualmente usa lista negra, não allowlist. Fase 2.

---

#### F.3. BoostTool database-query Hardening ❌

Ver C.1. Fase 2 — alta prioridade.

---

#### F.4. PostgreSQL Read-Only User ❌

Não provisionado. Script de criação de usuário `aidev_readonly` com `GRANT SELECT` por banco do Projeto Alvo e conexão `readonly` em `config/database.php` do Projeto Alvo. Fase 2.

---

#### F.5. Circuit Breakers ✅ (parcial)

- ✅ `tasks.max_retries` com `canRetry()` implementado.
- ✅ Subtask tem `retry_count` e `max_retries`.
- ✅ Escalada para `escalated` quando `max_retries` excedido.
- ❌ Limite de custo por task (por tokens/USD) — não implementado.
- ❌ Timeout de Job configurável por agente via `agents_config` — atualmente hardcoded.

---

### Módulo G — FileLockManager e Git Branching

#### G.1. FileLockManager ✅⚠️

**O que existe:** A lógica de mutex está implementada diretamente no `Subtask::hasFileLockConflict()` — verifica se outra subtask `running` trava os mesmos arquivos.

**O que falta:**
- ⚠️ O PRD especifica `FileLockManager.php` como Service em `app/Services/`. A lógica está no Model. Funciona, mas viola separação de responsabilidades. Extração para Service é Fase 2.

---

#### G.2. Git Branching por Task ❌

**O que existe:** O `SpecialistAgent` instrui o LLM a criar branch `feature/subtask-{id}` (por subtask, não por task). O campo `tasks.git_branch` existe na tabela mas não é populado pelo `OrchestratorJob`.

**O que falta:**
- ❌ `OrchestratorJob` deve criar branch `task/{task_uuid_short}` no início e registrar em `tasks.git_branch`.
- ❌ Todas as subtasks devem trabalhar no mesmo branch da task (não em branches separados por subtask).
- ❌ `GitOperationTool` não tem action `revert` para rollback preciso por `commit_hash`.

**Necessário (Fase 2):**
1. `OrchestratorJob` cria `git checkout -b task/{uuid_short}` e salva em `tasks.git_branch`.
2. `SpecialistAgent` remove a criação de branch — trabalha no branch da task.
3. `GitOperationTool` recebe action `revert` com `commit_hash`.

---

### Módulo H — Sentinela (Self-Healing Runtime)

❌ Não existe. Fase 2. Exception Handler customizado para Projetos Alvo que gera tasks automaticamente no ai-dev-core com `source: sentinel`.

---

### Módulo I — Horizon e Filas

#### I.1. Config do Horizon ⚠️

**Problema crítico:** O `SubagentJob` publica na fila `agents`, mas o Horizon tem supervisor configurado para fila `subagent`. Os jobs de subtask **nunca serão processados** com a config atual.

**Tabela de divergências:**

| Job | Fila usada pelo Job | Supervisor Horizon | Status |
|---|---|---|---|
| `OrchestratorJob` | `orchestrator` | `orchestrator` | ✅ |
| `SubagentJob` | `agents` | `subagent` | ❌ Bug |
| `QAAuditJob` | `qa` | `qa` | ✅ |

**Necessário (Fase 1 — bloqueante):**
1. Alinhar fila do `SubagentJob` (futuro `ProcessSubtaskJob`) para `subtasks` e criar supervisor correspondente no Horizon.

---

#### I.2. Providers em config/ai.php ⚠️

**Problema crítico:** OrchestratorJob e SubagentJob chamam providers inexistentes:
- `OrchestratorJob` → `'orchestrator_chain'` — **não existe**
- `SubagentJob` → `'specialist_chain'` — **não existe**
- Existe apenas: `openrouter`, `openrouter_chain` (failover), e outros providers não-Anthropic.

**Necessário (Fase 1 — bloqueante):**
Escolher uma das abordagens:
- **Opção A:** Renomear as chamadas para `'openrouter_chain'` nos dois Jobs.
- **Opção B:** Adicionar `orchestrator_chain` e `specialist_chain` como aliases de failover no `config/ai.php`.

---

### Módulo J — Services

#### J.1. SystemContextService ✅

Implementado. Monta contexto dinâmico (OS, DB, stack, rotas) para prompts de IAs de interação.

#### J.2. PRDValidator ❌

Não existe. Fase 2. Deve validar PRD contra o JSON Schema antes de aceitar task:
- Campos obrigatórios presentes
- Enums válidos
- Paths com prefixo `/var/www/html/projetos/`
- Dedup por título/hash

#### J.3. TaskOrchestrator ❌

Não existe. Fase 2. Coordena o pipeline `Agent → QA → Security → Performance → Git`.

#### J.4. FileLockManager ❌ (como Service)

Ver G.1. Lógica existe no Model — extrair para Service em Fase 2.

---

## 3. Plano de Refatoração por Fase

---

### Fase 1 — Bugs Críticos e Core Loop Funcional

**Objetivo:** Eliminar bugs que impedem o pipeline de rodar e garantir que o ciclo Task → Orchestrator → Specialist → QA → Commit funcione end-to-end.

**Critérios de Aceite da Fase 1:**

- [ ] **[Bug #1 — Bloqueante]** Corrigir provider inexistente no `OrchestratorJob` (`orchestrator_chain` → `openrouter_chain` ou criar o provider)
- [ ] **[Bug #2 — Bloqueante]** Corrigir provider inexistente no `SubagentJob` (`specialist_chain` → `openrouter_chain` ou criar o provider)
- [ ] **[Bug #3 — Bloqueante]** Alinhar fila do `SubagentJob` com supervisor do Horizon (`agents` → `subtasks`, criar supervisor)
- [ ] **[Bug #4 — Crítico]** `BoostTool` project-path-aware: adicionar `__construct(private readonly ?string $workingDirectory = null)` e rotear sub-tools para o `workingDirectory`
- [ ] **[Bug #5 — Crítico]** `SpecialistAgent` passar `new BoostTool($this->projectPath)` em vez de `new BoostTool`
- [ ] **[Bug #6 — Crítico]** `QAAuditorAgent` passar `new BoostTool($this->projectPath)` — o auditor precisa ler o banco/docs do Projeto Alvo
- [ ] **[Bug #7]** `DocSearchTool` receber `workingDirectory` e passar ao `BoostTool` interno
- [ ] **[Melhoria]** `SpecialistAgent` receber `assigned_agent` slug no constructor e adaptar `instructions()` por especialidade
- [ ] **[Melhoria]** Renomear `SubagentJob` → `ProcessSubtaskJob` (mantendo alias para retrocompatibilidade da fila)
- [ ] Teste end-to-end documentado: task "Criar Model de Post" no Projeto Alvo executada de ponta a ponta sem intervenção humana

---

### Fase 2 — Qualidade, Segurança e Observabilidade

**Objetivo:** `HasStructuredOutput`, auditoria de tools, segurança, pipeline completo com Security + Performance.

**Critérios de Aceite da Fase 2:**

- [ ] **[Alta — segurança/validação]** `HasStructuredOutput` implementado em: `OrchestratorAgent`, `QAAuditorAgent`, `SpecificationAgent`, `QuotationAgent`
- [ ] **[Alta — auditoria]** Migration `tool_calls_log` + Model `ToolCallLog` + listener `Tool::dispatched()` em `AppServiceProvider`
- [ ] **[Alta — segurança]** `BoostTool.database-query` hardening: schema estruturado com allowlist, redação de campos sensíveis, conexão `readonly`, cap 5.000 chars
- [ ] Migration `agent_executions` + Model `AgentExecution`
- [ ] Migration `webhooks_config` + Model `WebhookConfig`
- [ ] `SecuritySpecialist` agent implementado (`Agent, HasStructuredOutput, HasTools`)
- [ ] `PerformanceAnalyst` agent implementado (`Agent, HasStructuredOutput, HasTools`) com Pest 4 browser tests
- [ ] `SecurityAuditJob` implementado (fila `security`)
- [ ] `PerformanceAnalysisJob` implementado (fila `performance`)
- [ ] `QAAuditJob` despachar `SecurityAuditJob` após todas subtasks aprovadas (em vez de ir direto para `completed`)
- [ ] `QAAuditJob` remover auto-approve em caso de falha de parse
- [ ] `ShellExecuteTool` substituir lista negra por allowlist explícita de binários
- [ ] `GitOperationTool` receber action `revert` com `commit_hash`
- [ ] `OrchestratorJob` criar branch `task/{uuid_short}` e salvar em `tasks.git_branch`
- [ ] `SpecialistAgent` remover criação de branch — trabalhar no branch da task
- [ ] Sentinela implementado no Projeto Alvo (Exception Handler gerando tasks `source: sentinel`)
- [ ] `PRDValidator.php` implementado em `app/Services/`
- [ ] `FileLockManager.php` extraído para `app/Services/` (lógica saindo do Model Subtask)
- [ ] PostgreSQL read-only user provisionado por Projeto Alvo (script documentado em `INFRASTRUCTURE.md`)
- [ ] Horizon configurado com supervisores para filas `subtasks`, `security`, `performance`

---

### Fase 3 — Inteligência e Memória

**Objetivo:** RAG vetorial, compressão de contexto, auto-evolução, redes sociais.

**Critérios de Aceite da Fase 3:**

- [ ] Migration `problems_solutions` com coluna `vector(1536)` via pgvector
- [ ] `ProblemSolutionRecorder` (Listener) auto-alimentando `problems_solutions` após resolução via Sentinela
- [ ] `SimilaritySearch::usingModel(ProblemSolution::class, 'embedding')->minSimilarity(0.7)` como Tool SDK dinâmica
- [ ] Migration `context_library` + seed inicial com padrões TALL
- [ ] `ContextCompressor` Agent (Ollama `qwen2.5:0.5b`) + `ContextCompressionJob`
- [ ] Prompt Caching com ordem: estático → semi-estático → dinâmico
- [ ] `DocSearchTool` fallback web (DuckDuckGo / Firecrawl) quando Boost não tiver a doc
- [ ] Webhooks de entrada (GitHub, CI/CD) → tasks automáticas
- [ ] `SocialPostingAgent` + integração `hamzahassanm/laravel-social-auto-post`
- [ ] OWASP ZAP em modo headless (complementar ao Nikto)

---

## 4. Restrições Técnicas (Invioláveis)

1. **Stack exclusiva TALL:** Tailwind + Alpine.js + Laravel + Livewire. Proibido React, Vue, Inertia.js.
2. **Agentes escrevem apenas dentro do `local_path` do Projeto Alvo.** Nunca no filesystem do ai-dev-core.
3. **`FileWriteTool` actions válidas:** `write`, `replace`, `mkdir`. `action=patch` não existe.
4. **Dusk removido.** Testes de browser: Pest v4 com `visit()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`.
5. **`HasStructuredOutput` obrigatório** em agentes que retornam JSON estruturado. Proibido parse manual.
6. **Cap de 5.000 chars** no output do `BoostTool.database-query`.
7. **Horizon** (não `Concurrency::run()`) para paralelização. Ver `MIGRATION_LARAVEL13.md §3.6`.
8. **PRDValidator** rejeita toda task sem os campos obrigatórios do schema.
9. **`BoostTool` sempre instanciado com `workingDirectory`** do Projeto Alvo — nunca sem argumento em código de produção.

---

## 5. Mapeamento de Providers LLM (config/ai.php)

| Provider key | Driver | Uso |
|---|---|---|
| `openrouter` | openai (OpenRouter URL) | Default — todos os agentes |
| `openrouter_chain` | failover → [openrouter, openai] | Fallback automático |
| `ollama` | ollama | Fase 3 — ContextCompressor, embeddings |

**Todos os Jobs devem usar `openrouter_chain`** (com failover) em vez de `openrouter` direto. Os providers `orchestrator_chain` e `specialist_chain` referenciados no código atual não existem e devem ser corrigidos.

---

## 6. Tabelas do Banco (Referência Canônica)

### Operacionais (ai-dev-core — todas no banco `ai_dev_core`):

| Tabela | Fase | Status |
|---|---|---|
| `users` | Core | ✅ |
| `projects` | 1 | ✅ |
| `project_specifications` | 1 | ✅ |
| `project_modules` | 1 | ✅ |
| `project_quotations` | 1 | ✅ |
| `tasks` | 1 | ✅ |
| `subtasks` | 1 | ✅ |
| `agents_config` | 1 | ✅ |
| `task_transitions` | 1 | ✅ |
| `agent_conversations` | 1 | ✅ |
| `agent_conversation_messages` | 1 | ✅ (SDK) |
| `system_settings` | 1 | ✅ |
| `social_accounts` | 1 | ✅ (migration; integração Fase 3) |
| `agent_executions` | 2 | ❌ |
| `tool_calls_log` | 2 | ❌ |
| `webhooks_config` | 2 | ❌ |
| `context_library` | 3 | ❌ |
| `problems_solutions` | 3 | ❌ |

---

## 7. URLs de Referência Técnica

| Recurso | URL |
|---|---|
| Laravel AI SDK — Multi-Agent Workflows | https://laravel.com/blog/building-multi-agent-workflows-with-the-laravel-ai-sdk |
| Laravel AI SDK — Production-Safe Database Tools | https://laravel.com/blog/laravel-ai-sdk-building-production-safe-database-tools-for-agents |
| Filament v5 Docs | https://filamentphp.com/docs/ |
| Laravel 13 Docs | https://laravel.com/docs/13.x |
| Pest v4 Docs | https://pestphp.com/docs |
| Laravel Horizon Docs | https://laravel.com/docs/horizon |
