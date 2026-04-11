# 🤖 AI-Dev (AndradeItalo.ai)

**Sistema de Desenvolvimento de Software Autônomo, Multi-Agente e Auto-Corretivo.**

O AI-Dev é um ecossistema que utiliza múltiplos agentes de IA coordenados para desenvolver, testar, auditar e fazer deploy de aplicações Laravel/TALL automaticamente. Os agentes operam em background, guiados por um banco de dados relacional PostgreSQL 16 (com pgvector), com memória vetorial de longo prazo e auto-correção nativa via Sentinela.

---

## 🏗️ Stack Obrigatória

| Camada | Tecnologia |
|---|---|
| **Backend** | Laravel 13 + PHP 8.3 |
| **Frontend** | Livewire 4 + Alpine.js v3 + Tailwind CSS v4 |
| **Admin Panel** | Filament v5 |
| **Animações** | Anime.js |
| **Banco Relacional** | PostgreSQL 16 + pgvector (busca vetorial nativa) |
| **Filas/Cache** | Redis 7.0 |
| **AI SDK** | Laravel AI SDK (`laravel/ai`) — Agents, Tools, Structured Output, Conversations |
| **MCP** | Laravel MCP (`laravel/mcp`) — Model Context Protocol servers para interação com agentes |
| **Boost** | Laravel Boost (`laravel/boost`) — Guidelines, Skills e Documentation API para agentes |
| **IA Principal** | Gemini 3.1 Flash Lite Preview (Executor) + Claude Sonnet 4-6 (Planner/QA/Security) |
| **IA Local** | Ollama (qwen2.5:0.5b para compressão e embeddings) |
| **Orquestração** | Supervisor + Laravel Horizon |

---

## 📐 Arquitetura

```
Humano/Webhook → [Task + PRD] → Orchestrator → Sub-PRDs → Subagentes → QA Auditor → Git Push
                                                                           ↑
                                                                    Sentinela (Self-Healing)
```

O sistema utiliza 3 classes de agentes, todos implementados como **Agent classes** do Laravel AI SDK (`laravel/ai`):

1. **OrchestratorAgent (Planner)** — `implements Agent, HasStructuredOutput, HasTools` — Recebe o PRD principal e o decompõe em Sub-PRDs focados
2. **Specialist Agents (Executors)** — `implements Agent, Conversational, HasTools` — Especialistas (Backend, Frontend, Filament, DBA, DevOps) que executam cada Sub-PRD
3. **QAAuditorAgent (Judge)** — `implements Agent, HasStructuredOutput` — Audita toda entrega contra o PRD original e rejeita se não atender aos critérios

A comunicação é feita via **Laravel Queue + Redis**, com máquina de estados no PostgreSQL, conversas persistidas via `RemembersConversations` do SDK, e rollback via Git branch por task.

---

## 📁 Documentação

| Arquivo | Conteúdo |
|---|---|
| [ARCHITECTURE.md](./ARCHITECTURE.md) | Visão completa da arquitetura: modelagem de banco (12 tabelas), protocolo inter-agentes, fluxo de vida das tasks, memória, métricas e fases de implementação |
| [ADMIN_GUIDE.md](./ADMIN_GUIDE.md) | Guia do Painel Administrativo (Web UI): gestão de módulos hierárquicos, prioridades, dependências e orçamentos |
| [PRD_SCHEMA.md](./PRD_SCHEMA.md) | JSON Schema formal do PRD (Product Requirement Document) com exemplos completos |
| [FERRAMENTAS.md](./FERRAMENTAS.md) | Catálogo das 8 ferramentas consolidadas com JSON Schemas de entrada/saída |
| [PROMPTS.md](./PROMPTS.md) | Engenharia de prompts: regras universais, role descriptions, template completo do prompt montado, segurança anti-injection |
| [INFRASTRUCTURE.md](./INFRASTRUCTURE.md) | Requisitos de servidor, instalação passo-a-passo, consumo de recursos estimado |

---

## 🗄️ Modelagem do Banco de Dados

O AI-Dev utiliza **12 tabelas** no PostgreSQL para controle total do estado:

| Tabela | Propósito |
|---|---|
| `projects` | Cadastro de projetos/aplicações gerenciados |
| `tasks` | Tarefas de desenvolvimento com PRD em JSON |
| `subtasks` | Decomposição granular feita pelo Orchestrator |
| `agents_config` | Configuração dinâmica de cada agente (modelo, temperatura, prompt) |
| `context_library` | Padrões de código TALL obrigatórios (few-shot fixo) |
| `task_transitions` | Log de auditoria de toda mudança de estado |
| `agent_executions` | Log de cada chamada LLM (tokens, custo, latência) |
| `tool_calls_log` | Registro de cada ferramenta executada (segurança) |
| `problems_solutions` | Base de conhecimento auto-alimentada (RAG vetorial via pgvector) |
| `agent_conversations` | Conversas persistidas automaticamente pelo Laravel AI SDK |
| `agent_conversation_messages` | Mensagens das conversas (gerenciado pelo SDK) |
| `webhooks_config` | Configuração de webhooks de entrada (GitHub, CI/CD) |

---

## 🔧 Ferramentas (9 Atômicas — `implements Laravel\Ai\Contracts\Tool`)

Todas as ferramentas implementam o contrato `Tool` do Laravel AI SDK, com `schema(JsonSchema $schema): array` para validação de entrada e `handle(Request $request): string` para execução. O SDK despacha automaticamente as tool calls do LLM para o método `handle()` correto.

| # | Ferramenta | Ações Principais |
|---|---|---|
| 1 | **ShellTool** | Terminal, artisan, npm, composer |
| 2 | **FileTool** | Read, write, patch, insert, delete, rename, tree |
| 3 | **DatabaseTool** | Describe, query, execute, dump, migrations |
| 4 | **GitTool** | Status, commit, push, branch, merge, GitHub API |
| 5 | **SearchTool** | DuckDuckGo, Firecrawl scraping, grep, find |
| 6 | **TestTool** | Pest/PHPUnit, Dusk, screenshots, coverage |
| 7 | **SecurityTool** | Enlightn, Larastan, Nikto, SQLMap, dependency audit |
| 8 | **DocsTool** | Markdown, TODOs, documentação técnica |
| 9 | **MetaTool** | Criar novas ferramentas, logging de impossibilidades |

---

## 🎯 Fases de Implementação

### Fase 1: Core Loop (MVP) — Laravel AI SDK
- Ciclo completo: Task → OrchestratorAgent → Specialist Agents → QAAuditorAgent → Git Commit
- Agent classes com `HasTools`, `HasStructuredOutput`, `Conversational` (SDK nativo)
- 9 Tools (`implements Tool`) + config/ai.php + PostgreSQL + Redis + Supervisor

### Fase 2: Qualidade, Segurança e UI
- QA Auditor com Claude + Security Specialist + Performance Analyst
- Sentinel Self-Healing + Enlightn + Larastan + Nikto + SQLMap
- Filament v5 Dashboard + Circuit breakers + Git branching por task
- Laravel MCP servers para integração com agentes externos

### Fase 3: IA Avançada
- RAG Vetorial via pgvector nativo no PostgreSQL + Compressão de contexto (Ollama)
- Laravel Boost (guidelines, skills, documentation API)
- Multi-provider failover via `Lab` enum + Auto-alimentação de conhecimento

---

## 🖥️ Servidor

- **Ubuntu 24.04 LTS** — 2 vCPUs, 8 GB RAM
- **IP:** 10.1.1.86 (Supreme)
- **Consumo total estimado:** ~3.3 GB RAM com todos os componentes rodando

---

## 📄 Licença

Projeto proprietário — AndradeItalo.ai © 2026
