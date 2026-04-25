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
| **LLM PREMIUM (Planejamento)** | Configurável via System Settings (ex: OpenRouter/Anthropic, Kimi K2.6) | ✅ |
| **LLM HIGH (Código/QA)** | Configurável via System Settings (ex: OpenRouter/Anthropic, Kimi K2.6) | ✅ |
| **LLM FAST (Docs/Jobs)** | Configurável via System Settings (ex: OpenRouter/Anthropic, OpenAI GPT-4o) | ✅ |
| **IA DO SISTEMA (Produção)** | Configurável via System Settings (ex: Kimi K2.6, Claude Haiku) | ✅ |
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

#### B.0. ProjectPrdAgent ✅ (Novo — Granularidade Progressiva)

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Gera o PRD Master do projeto contendo **apenas módulos de alto nível de negócio** (zero submódulos).

**Instruções:** Explicitamente proíbe a geração de submódulos no nível do projeto e também proíbe incluir `Chatbox` e `Segurança` em `modules`. Retorna JSON com `title`, `objective`, metas, métricas, personas/jornadas, fronteiras de escopo e `modules[]` enriquecido (`source_features`, resultados, jornadas, requisitos de conteúdo/dados e sinais de aceite). O `StandardProjectModuleService` anexa `standard_modules` com Chatbox/Segurança depois da resposta.

**Job associado:** `GenerateProjectPrdJob` — fila `orchestrator`, timeout 600s.

**Aprovação:** `Project::approvePrd()` libera a geração do Blueprint Técnico Global. A criação dos módulos raiz só ocorre depois de `Project::approveBlueprint()`.

---

#### B.0.1. ProjectBlueprintAgent ✅ (Novo — Blueprint Técnico Progressivo)

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Gera o Blueprint Técnico Global depois do PRD Master e antes dos módulos.

**Instruções:** Retorna JSON com MER/ERD conceitual sem campos, casos de uso, workflows, arquitetura C4 simplificada, integrações, API surface, cobertura por módulo, lifecycle de dados/conteúdo, estados conceituais, riscos, decisões não funcionais e perguntas abertas. Não cria código, migrations ou scaffold físico.

**Job associado:** `GenerateProjectBlueprintJob` — fila `orchestrator`, timeout 600s.

**Aprovação:** `Project::approveBlueprint()` valida `blueprint_payload` e libera `Project::createModulesFromPrd()`.

---

#### B.0.2. ModulePrdAgent ✅ (Novo — Granularidade Progressiva)

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Gera PRD Técnico detalhado para **um módulo específico**.

**Instruções:** Recebe contexto do projeto + nome/descrição do módulo + Blueprint Técnico Global atual. Retorna JSON com: `title`, `objective`, `scope`, `database_schema`, `blueprint_contribution`, `api_endpoints`, `business_rules`, `validation_rules`, `permissions`, `state_model`, `components`, `implementation_items`, `workflows`, `acceptance_criteria`, `qa_scenarios`, `edge_cases`, `estimated_complexity`, `estimated_hours`, `needs_submodules` (boolean), `submodules[]`.

**Blueprint progressivo:** `ProjectBlueprintService` incorpora `blueprint_contribution` de cada módulo/submódulo em `projects.blueprint_payload`, adicionando campos, relacionamentos, workflows, componentes e APIs sem perder o desenho global.

**Decisão inteligente:** O próprio agente decide se o módulo precisa de submódulos baseado na complexidade. Módulos simples (ex: "Configurações") retornam `needs_submodules = false` e viram folhas imediatas.

**Jobs associados:**
- `GenerateModulePrdJob` — fila `orchestrator`, timeout 600s
- `GenerateModuleSubmodulesJob` — cria submódulos do PRD quando `needs_submodules = true`
- `GenerateModuleTasksJob` — cria tasks do PRD quando `needs_submodules = false` (folha), priorizando `implementation_items` e usando critérios de aceite apenas como fallback quando não há superfície implementável.

---

#### B.1. OrchestratorAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Decompõe PRD de **task** em Sub-PRDs retornando array JSON.

**O que falta / precisa refatorar:**
- ❌ Não implementa `HasStructuredOutput` — saída JSON é parseada manualmente com `json_decode()` e `preg_replace` para remover markdown fences. Risco de falha silenciosa de formato.
- ❌ Não implementa `HasTools` — o PRD arquitetural exige ferramentas para leitura de contexto.
- ✅ O `OrchestratorJob` agora usa `AiRuntimeConfigService::apply(LEVEL_PREMIUM)` para resolver provider/model/key dinamicamente do `SystemSetting`. Não há mais hardcode de provider.

**Refatoração necessária (Fase 1):**
1. Implementar `HasStructuredOutput` com schema do array de Sub-PRDs.

---

#### B.2. QAAuditorAgent ⚠️

**O que existe:** Implementa `Agent, HasTools` com `BoostTool`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_HIGH)`. Retorna JSON de auditoria.

**O que falta / precisa refatorar:**
- ❌ Não implementa `HasStructuredOutput` — parse manual de JSON com `preg_replace` e `json_decode`. O `QAAuditJob` auto-aprova em caso de falha de parse (comportamento perigoso).
- ⚠️ Schema de retorno atual é simplificado (`approved`, `overall_quality`, `issues`, `summary`). O schema canônico do PRD exige: `criteria_checklist`, `recommendation`, `issues` com `file/line/severity/description/suggestion`.

**Refatoração necessária (Fase 2 — alta prioridade):**
1. Implementar `HasStructuredOutput` com schema canônico completo.
2. Eliminar o auto-approve em caso de falha de parse — deve falhar explicitamente.
3. Atualizar `instructions()` para exigir o schema canônico.

---

#### B.3. SpecialistAgent ✅⚠️

**O que existe:** Implementa `Agent, HasTools`. Recebe `$projectPath` no constructor. Injeta as 6 tools escopadas ao `projectPath`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_HIGH)`.

**Observação de design:** O PRD arquitetural listava classes separadas por especialidade (BackendSpecialist, FrontendSpecialist, etc.). O código implementou um `SpecialistAgent` genérico que recebe `assigned_agent` como string. Esta é uma decisão válida — agente genérico + specialization via prompt é mais simples e funcional. O PRD passa a documentar este design.

**O que falta:**
- ✅ O `ProcessSubtaskJob` usa `AiRuntimeConfigService::apply(LEVEL_HIGH)` para resolver provider/model/key dinamicamente do `SystemSetting`. Não há mais hardcode de provider.
- ⚠️ `instructions()` não diferenciam o tipo de especialista (`assigned_agent`). O agente deveria adaptar seu comportamento ao tipo recebido.
- ❌ Agente não recebe `assigned_agent` do subtask para adaptar o prompt — recebe apenas `$projectPath`.

**Refatoração necessária (Fase 1):**
1. Fazer `SpecialistAgent` receber o `assigned_agent` slug no constructor e usar no `instructions()`.
2. Fazer `SpecialistAgent` receber o `assigned_agent` slug no constructor e usar no `instructions()`.

---

#### B.4. RefineDescriptionAgent ✅

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Usa `SystemContextService` para contexto dinâmico da stack. Sem `HasTools`.

**Status:** Alinhado com o projeto. Sem refatoração necessária no momento.

---

#### B.5. SpecificationAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Retorna JSON de especificação técnica.

**O que falta:**
- ❌ Não implementa `HasStructuredOutput` — parse manual no `GenerateProjectSpecificationJob`.
- ❌ Não tem `HasTools`.

**Refatoração necessária (Fase 2):**
1. Implementar `HasStructuredOutput` com schema da especificação.

---

#### B.6. QuotationAgent ⚠️

**O que existe:** Implementa `Agent`, usa `Promptable`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_PREMIUM)`. Retorna JSON de orçamento.

**O que falta:**
- ❌ Não implementa `HasStructuredOutput` — parse manual no `GenerateProjectQuotationJob`.
- ❌ Não tem `HasTools`.

**Refatoração necessária (Fase 2 — alta prioridade):**
1. Implementar `HasStructuredOutput` com schema de orçamento (horas por área, custo humano vs. AI-Dev, ROI).

---

#### B.7. DocsAgent ✅

**O que existe:** Implementa `Agent, HasTools` com `BoostTool`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_FAST)`. Busca documentação via `search-docs`.

**Status:** Alinhado. Sem refatoração necessária no momento.

---

#### B.8. SecuritySpecialist ❌

Não existe. Deve ser criado na Fase 2. Implementará `Agent, HasStructuredOutput, HasTools`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_HIGH)`. Rodará Enlightn, Nikto, `composer audit`. Retornará JSON estruturado com vulnerabilidades encontradas.

---

#### B.9. PerformanceAnalyst ❌

Não existe. Deve ser criado na Fase 2. Implementará `Agent, HasStructuredOutput, HasTools`. Provider/model resolvidos dinamicamente via `AiRuntimeConfigService::apply(LEVEL_HIGH)`. Rodará Pest 4 browser tests (`visit()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`). Retornará JSON estruturado com métricas de performance.

---

### Módulo C — Ferramentas Atômicas (Tool Layer)

#### C.1. BoostTool ✅⚠️

**O que existe:** Implementa `Tool`. Mapeia sub-tools do Boost (`search-docs`, `database-schema`, `database-query`, `browser-logs`, `last-error`, `application-info`) e executa `php artisan boost:execute-tool` dentro do `projects.local_path` do Projeto Alvo.

**Status atual:**
- ✅ É project-path-aware via `__construct(?string $workingDirectory)`.
- ✅ `SpecialistAgent`, `DocsAgent`, `DocSearchTool` e `QAAuditorAgent` propagam o `local_path`.
- ✅ `database-query` usa payload estruturado, valida tabela/coluna contra o schema real do alvo, bloqueia tabelas internas, bloqueia colunas sensíveis, aplica allowlist de operadores e limita saída a 5.000 chars.

**Pendente para produção:**
1. Provisionar conexão `readonly` em cada Projeto Alvo e passar `database="readonly"` nas chamadas de consulta.
2. Implementar listener de auditoria para popular `tool_calls_log` automaticamente em cada tool call.

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

#### C.5. ShellExecuteTool ✅⚠️

**O que existe:** Implementa `Tool`. Recebe `$workingDirectory` no constructor. Bloqueia operadores de shell e executa apenas comandos allowlisted (`php artisan`, `composer`, `npm`, `npx`, `pint`, `pest`, `phpstan`, `phpunit`, `enlightn`, `nikto`, `sqlmap`).

**Pendente:** Ampliar telemetria/auditoria por evento `Tool::dispatched()`; a execução local já está limitada por allowlist.

---

#### C.6. DocSearchTool ✅

**O que existe:** Implementa `Tool`. Delega para `DocsAgent` que usa `BoostTool.search-docs` internamente.

**Status:** Alinhado. Recebe `workingDirectory` e o propaga ao `DocsAgent`/`BoostTool`.

---

### Módulo D — Pipeline de Jobs

#### D.1. OrchestratorJob ⚠️

**O que existe:** Implementa `ShouldQueue`. Fila `orchestrator`. Transição `pending → in_progress`, chama `OrchestratorAgent`, cria subtasks, despacha subtasks prontas via `ProcessSubtaskJob`.

**Problemas:**
- ✅ Usa `AiRuntimeConfigService::apply(LEVEL_PREMIUM)` para resolver provider/model/key dinamicamente do `SystemSetting`.
- ⚠️ Após `HasStructuredOutput` ser implementado no `OrchestratorAgent`, o parse manual de JSON neste Job deve ser removido.
- ❌ Não cria branch git por task (`task/{uuid_short}`) antes de despachar subtasks. Git branching por task é Fase 2.

**Refatoração necessária (Fase 1):**

2. Remover parse manual após implementar `HasStructuredOutput`.

---

#### D.2. ProcessSubtaskJob ✅⚠️

**O que existe:** Implementa `ShouldQueue`. Fila `subtasks`. Instancia `SpecialistAgent($workDir, $assignedAgent)`, chama `prompt()`, captura diff do working tree/staged changes e despacha `QAAuditJob`.

**Problemas:**
- ✅ Usa `AiRuntimeConfigService::apply(LEVEL_HIGH)` para resolver provider/model/key dinamicamente.
- ✅ Passa `assigned_agent` ao `SpecialistAgent`.
- ⚠️ File locks ainda vivem no Model `Subtask`; extrair para service na Fase 2.
- ⚠️ Não cria branch isolado por task.

**Refatoração necessária (Fase 2):**
1. Extrair FileLockManager para `app/Services`.
2. Implementar branch por task e rollback por commit hash.
3. Integrar auditoria automática de tool calls.

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

#### D.6. Jobs de Granularidade Progressiva (Novos — Pipeline Ativo)

| Job | Fila | Propósito | Timeout |
|---|---|---|---|
| `GenerateProjectPrdJob` | `orchestrator` | Gera PRD Master do projeto via `ProjectPrdAgent` (módulos de negócio) e anexa `standard_modules` | 600s |
| `GenerateProjectBlueprintJob` | `orchestrator` | Gera Blueprint Técnico Global via `ProjectBlueprintAgent` | 600s |
| `GenerateModulePrdJob` | `orchestrator` | Gera PRD Técnico de um módulo via `ModulePrdAgent` e incorpora contribuição ao Blueprint | 600s |
| `GenerateModuleSubmodulesJob` | `orchestrator` | Cria submódulos a partir do `prd_payload.submodules` do módulo | 600s |
| `GenerateModuleTasksJob` | `orchestrator` | Cria tasks a partir do PRD técnico de um módulo folha | 600s |

**Fluxo de ativação (UI Filament):**
1. Usuário clica "Gerar PRD do Projeto" → `GenerateProjectPrdJob`
2. Usuário aprova PRD → `approvePrd()` + `GenerateProjectBlueprintJob`
3. Usuário aprova Blueprint → `ApproveProjectBlueprintJob` executa `approveBlueprint()` + `createModulesFromPrd()` e sincroniza documentação `.ai-dev`; não instala o Projeto Alvo
4. Usuário entra em um módulo → clica "Gerar PRD do Módulo" → `GenerateModulePrdJob`
5. Se PRD retorna `needs_submodules = true` → clica "Criar Submódulos" → `GenerateModuleSubmodulesJob`
6. Se PRD retorna `needs_submodules = false` → clica "Criar Tasks" → `GenerateModuleTasksJob`

#### D.7. Jobs Auxiliares existentes (fora do pipeline principal)

| Job | Fila | Propósito |
|---|---|---|
| `GenerateProjectSpecificationJob` | `orchestrator` | Gera spec técnica via `SpecificationAgent` (legado) |
| `GenerateProjectQuotationJob` | `orchestrator` | Gera orçamento via `QuotationAgent` |
| `GenerateTasksFromSpecJob` | `orchestrator` | Cria tasks a partir de spec aprovada (legado) |
| `ScaffoldProjectJob` | `default` | Scaffolding inicial do Projeto Alvo via `instalar_projeto.sh`, disparado após aprovação do orçamento, incluindo cópia do core padrão Chatbox/Segurança e provisionamento individual de AI SDK/MCP/Boost. Se arquivos obrigatórios não existirem, marca o projeto como `scaffold_failed` |

**Status:** Existem e funcionam. `SpecificationAgent` e `GenerateTasksFromSpecJob` são legados — o fluxo ativo usa `ProjectPrdAgent` + `ModulePrdAgent`.

---

### Módulo E — Admin Panel (Filament v5)

#### E.1. Resources ✅

Todos já implementados:

| Resource | Status |
|---|---|
| `ProjectResource` | ✅ Com aba "Módulos do Projeto" (ProjectModulesRelationManager), PRD do Projeto, navegação breadcrumb |
| `TaskResource` | ✅ Com form, table, view, SubtasksRelationManager, breadcrumb |
| `AgentConfigResource` | ✅ Implementado |
| `ProjectSpecificationResource` | ✅ Implementado (legado) |
| `ProjectModuleResource` | ✅ Com TasksRelationManager, breadcrumb hierárquico, ações de PRD/Submódulos/Tasks |
| `ProjectQuotationResource` | ✅ Implementado |
| `SocialAccountResource` | ✅ Migration criada, resource implementado (integração Fase 3) |

**Navegação:**
- `NavigationTree` component gera breadcrumbs em ViewProject, ViewProjectModule, ViewTask
- Links cruzados: project.name → ViewProject, module.name → ViewProjectModule em todas as tabelas
- ViewProjectModule mostra breadcrumb completo: Projeto > Pai > ... > Módulo Atual
- Layout reorganizado: todas as sections em 100% de largura, empilhadas verticalmente

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

#### F.2. ShellExecuteTool Binary Allowlist ✅

Ver C.5. A execução já usa allowlist de comandos e bloqueia operadores de shell.

---

#### F.3. BoostTool database-query Hardening ✅⚠️

Ver C.1. O wrapper já valida contra schema real, bloqueia campos sensíveis e limita saída; falta provisionar conexão `readonly` por Projeto Alvo.

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

#### I.1. Config do Horizon ✅

**Status:** Filas alinhadas no código atual. `ProcessSubtaskJob` publica em `subtasks`, `OrchestratorJob` publica em `orchestrator` e `QAAuditJob` publica em `qa`; o Horizon possui supervisors correspondentes.

**Tabela de filas:**

| Job | Fila usada pelo Job | Supervisor Horizon | Status |
|---|---|---|---|
| `OrchestratorJob` | `orchestrator` | `orchestrator` | ✅ |
| `ProcessSubtaskJob` | `subtasks` | `subtasks` | ✅ |
| `QAAuditJob` | `qa` | `qa` | ✅ |

**Pendente:** Operacionalizar Supervisor no servidor para manter Horizon ativo em produção.

---

#### I.2. Providers em config/ai.php ✅

**Resolvido:** Todos os Jobs e widgets usam `AiRuntimeConfigService` para resolver provider/model/key dinamicamente a partir do `SystemSetting`. Os 4 tiers (Premium, High, Fast, System) são independentes.


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

- [x] ✅ **[Bug #1 — Resolvido]** Todos os Jobs usam `AiRuntimeConfigService` para provider dinâmico
- [x] ✅ **[Bug #2 — Resolvido]** Todos os Jobs usam `AiRuntimeConfigService` para provider dinâmico
- [x] ✅ **[Bug #3 — Resolvido]** Fila de subtasks alinhada: `ProcessSubtaskJob` → `subtasks` e Horizon → `subtasks`
- [x] ✅ **[Bug #4 — Resolvido]** `BoostTool` project-path-aware via `workingDirectory` e `boost:execute-tool`
- [x] ✅ **[Bug #5 — Resolvido]** `SpecialistAgent` passa `new BoostTool($this->projectPath)`
- [x] ✅ **[Bug #6 — Resolvido]** `QAAuditorAgent` passa `new BoostTool($this->projectPath)`
- [x] ✅ **[Bug #7 — Resolvido]** `DocSearchTool` recebe `workingDirectory` e passa ao `DocsAgent`/`BoostTool`
- [x] ✅ **[Melhoria — Resolvida]** `SpecialistAgent` recebe `assigned_agent` slug no constructor
- [x] ✅ **[Melhoria — Resolvida]** Job de subagente consolidado como `ProcessSubtaskJob`
- [ ] Teste end-to-end documentado: task "Criar Model de Post" no Projeto Alvo executada de ponta a ponta sem intervenção humana

---

### Fase 2 — Qualidade, Segurança e Observabilidade

**Objetivo:** `HasStructuredOutput`, auditoria de tools, segurança, pipeline completo com Security + Performance.

**Critérios de Aceite da Fase 2:**

- [ ] **[Alta — segurança/validação]** `HasStructuredOutput` implementado em: `OrchestratorAgent`, `QAAuditorAgent`, `SpecificationAgent`, `QuotationAgent`
- [ ] **[Alta — auditoria]** Migration `tool_calls_log` + Model `ToolCallLog` + listener `Tool::dispatched()` em `AppServiceProvider`
- [ ] **[Alta — segurança]** Provisionar conexão `readonly` por Projeto Alvo para completar o hardening de defesa em profundidade do `BoostTool.database-query`
- [ ] Migration `agent_executions` + Model `AgentExecution`
- [ ] Migration `webhooks_config` + Model `WebhookConfig`
- [ ] `SecuritySpecialist` agent implementado (`Agent, HasStructuredOutput, HasTools`)
- [ ] `PerformanceAnalyst` agent implementado (`Agent, HasStructuredOutput, HasTools`) com Pest 4 browser tests
- [ ] `SecurityAuditJob` implementado (fila `security`)
- [ ] `PerformanceAnalysisJob` implementado (fila `performance`)
- [ ] `QAAuditJob` despachar `SecurityAuditJob` após todas subtasks aprovadas (em vez de ir direto para `completed`)
- [ ] `QAAuditJob` remover auto-approve em caso de falha de parse
- [x] ✅ `ShellExecuteTool` usa allowlist explícita de comandos/binários
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

**Todos os Jobs usam `AiRuntimeConfigService`** para resolver provider/model/key em runtime a partir do `SystemSetting`.

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

---

## 8. Registro de Implementação (Fase 1 e Início da Fase 2) — 2026-04-20

As seguintes melhorias e correções foram implementadas para alinhar o sistema com o PRD v2.0:

- **✅ Granularidade Progressiva:** `ProjectPrdAgent` gera apenas módulos; `ModulePrdAgent` gera PRD técnico por módulo decidindo `needs_submodules`; submódulos e tasks criados apenas nos níveis apropriados.
- **✅ Core Padrão por Projeto:** `StandardProjectModuleService` registra Chatbox/Segurança como módulos concluídos, injeta `standard_modules` no PRD e o `instalar_projeto.sh` copia os arquivos base para todo novo Projeto Alvo.
- **✅ Repositório por Projeto:** `ProjectRepositoryService` usa `projects.github_repo` para configurar `origin`, exportar PRDs/artefatos para `.ai-dev/` no repositório do Projeto Alvo, commitar e fazer push. O `QAAuditJob` também faz push dos commits aprovados.
- **✅ Novos Jobs:** `GenerateProjectPrdJob`, `GenerateModulePrdJob`, `GenerateModuleSubmodulesJob`, `GenerateModuleTasksJob` — todos na fila `orchestrator` com timeout 600s.
- **✅ Navegação Hierarchical:** `NavigationTree` component com breadcrumbs clicáveis em ViewProject, ViewProjectModule, ViewTask. Links cruzados entre recursos.
- **✅ Layout Vertical:** Todas as páginas ViewProject, ViewProjectModule, ViewTask usam sections empilhadas em 100% de largura.
- **✅ Providers de IA Dinâmicos:** Todos os Jobs e widgets usam `AiRuntimeConfigService` para resolver provider/model/key em runtime.
- **✅ Kimi Provider Persistente:** Fix para workers long-running — `Queue::before()` re-registra `Ai::extend('kimi')` antes de cada job.
- **✅ Renomeação de Jobs e Filas:** `SubagentJob` renomeado para `ProcessSubtaskJob` e fila movida de `agents` para `subtasks` no Horizon para consistência.
- **✅ Hardening de QA:** Remoção do auto-approve perigoso no `QAAuditJob` em caso de falha de parse; agora falhas de auditoria resultam em rejeição explícita.
- **✅ Especialização de Agentes:** `SpecialistAgent` agora adapta suas instruções com base na especialidade atribuída (`assigned_agent`).
