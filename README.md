# 🤖 AI-Dev (AndradeItalo.ai)

**Sistema de Desenvolvimento de Software Autônomo, Multi-Agente e Auto-Corretivo.**

O AI-Dev é um ecossistema que utiliza múltiplos agentes de IA coordenados para desenvolver, testar, auditar e fazer deploy de aplicações Laravel/TALL automaticamente. Os agentes operam em background, guiados por um banco de dados relacional PostgreSQL 16 (com pgvector), com memória vetorial de longo prazo, auto-correção nativa via Sentinela e publicação automática em redes sociais.

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
| **SDK Default** | OpenAI `gpt-5-nano` — provider padrão do Laravel AI SDK (`config/ai.php`) |
| **Orchestrator** | Gemini (primário) + Claude (backup) — maior cota de uso no Orchestrator |
| **Agents** | Claude Sonnet 4-6 (primário) + Gemini (backup) — código mais preciso nos especialistas |
| **IA Local** | Ollama (qwen2.5:0.5b para compressão e nomic-embed-text para embeddings) |
| **Redes Sociais** | `hamzahassanm/laravel-social-auto-post` — Facebook, Instagram, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest, Telegram |
| **Orquestração** | Supervisor + Laravel Horizon |

---

## 📐 Arquitetura

```
Humano/Webhook → [Task + PRD] → Orchestrator → Sub-PRDs → Subagentes → QA Auditor → Git Push
                                                                           ↑
                                                                    Sentinela (Self-Healing)
                                                                           ↓
                                                                    SocialTool (Auto-Post)
```

O sistema utiliza 3 classes de agentes, todos implementados como **Agent classes** do Laravel AI SDK (`laravel/ai`):

1. **OrchestratorAgent (Planner)** — `implements Agent, HasStructuredOutput, HasTools` — Recebe o PRD principal e o decompõe em Sub-PRDs focados. **Provider: Gemini (primário), Claude (backup)**
2. **Specialist Agents (Executors)** — `implements Agent, Conversational, HasTools` — Especialistas (Backend, Frontend, Filament, DBA, DevOps) que executam cada Sub-PRD. **Provider: Claude (primário), Gemini (backup)**
3. **QAAuditorAgent (Judge)** — `implements Agent, HasStructuredOutput` — Audita toda entrega contra o PRD original. **Provider: Claude (primário), Gemini (backup)**

A comunicação é feita via **Laravel Queue + Redis**, com máquina de estados no PostgreSQL, conversas persistidas via `RemembersConversations` do SDK, e rollback via Git branch por task.

---

## 📁 Documentação

| Arquivo | Conteúdo |
|---|---|
| [ARCHITECTURE.md](./ARCHITECTURE.md) | Visão completa da arquitetura: modelagem de banco (13 tabelas), protocolo inter-agentes, fluxo de vida das tasks, memória, métricas e fases de implementação |
| [PRD_SCHEMA.md](./PRD_SCHEMA.md) | JSON Schema formal do PRD (Product Requirement Document) com exemplos completos |
| [STANDARD_MODULES.md](./STANDARD_MODULES.md) | Catálogo de Módulos Padrão (Core Master) exigidos em todos os sistemas criados pela AndradeItalo.ai |
| [FERRAMENTAS.md](./FERRAMENTAS.md) | Catálogo das 10 ferramentas consolidadas com JSON Schemas de entrada/saída |
| [PROMPTS.md](./PROMPTS.md) | Engenharia de prompts: regras universais, role descriptions, template completo do prompt montado, segurança anti-injection |
| [INFRASTRUCTURE.md](./INFRASTRUCTURE.md) | Requisitos de servidor, instalação passo-a-passo, consumo de recursos estimado |

---

## 🗄️ Modelagem do Banco de Dados

A estrutura hierárquica do AI-Dev é: **Projeto → Módulos → Submódulos → Tasks → Subtasks**. Cada nível é subdividido ao máximo para que a automação processe unidades pequenas e precisas.

**Tabelas implementadas (Fase 1 — operacionais):**

| Tabela | Propósito |
|---|---|
| `projects` | Cadastro de projetos/aplicações gerenciados |
| `project_specifications` | Especificação técnica gerada pela IA; aprovação auto-cria módulos/submódulos |
| `project_modules` | Módulos e submódulos (hierárquico via parent_id): Projeto → Módulos → Submódulos |
| `project_quotations` | Orçamentos com comparativo de custo humano vs. AI-Dev e cálculo de ROI |
| `tasks` | Tarefas vinculadas a submódulos, com PRD em JSON |
| `subtasks` | Decomposição granular feita pelo Orchestrator (sub-PRDs por especialista) |
| `agents_config` | Configuração dinâmica de cada agente (modelo, temperatura, prompt) |
| `task_transitions` | Log de auditoria de toda mudança de estado |
| `agent_conversations` | Conversas persistidas automaticamente pelo Laravel AI SDK |
| `agent_conversation_messages` | Mensagens das conversas (gerenciado pelo SDK) |
| `social_accounts` | Credenciais de redes sociais por projeto (criptografadas) |

**Tabelas planejadas (Fase 2/3 — pendentes):**

| Tabela | Fase | Propósito |
|---|---|---|
| `agent_executions` | Fase 2 | Log de cada chamada LLM (tokens, custo, latência) |
| `tool_calls_log` | Fase 2 | Registro de cada ferramenta executada (auditoria de segurança) |
| `webhooks_config` | Fase 2 | Configuração de webhooks de entrada (GitHub, CI/CD) |
| `context_library` | Fase 3 | Padrões de código TALL obrigatórios (few-shot fixo) |
| `problems_solutions` | Fase 3 | Base de conhecimento auto-alimentada (RAG vetorial via pgvector) |

---

## 🔧 Ferramentas (10 Atômicas — `implements Laravel\Ai\Contracts\Tool`)

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
| 9 | **SocialTool** | Facebook, Instagram, Twitter/X, LinkedIn, TikTok, YouTube, Pinterest, Telegram |
| 10 | **MetaTool** | Criar novas ferramentas, logging de impossibilidades |

---

## 🎯 Fases de Implementação

### Fase 1: Core Loop (MVP) — Laravel AI SDK
- Ciclo completo: Task → OrchestratorAgent → Specialist Agents → QAAuditorAgent → Git Commit
- Agent classes com `HasTools`, `HasStructuredOutput`, `Conversational` (SDK nativo)
- 10 Tools (`implements Tool`) + config/ai.php (OpenAI gpt-5-nano default) + PostgreSQL + Redis + Supervisor
- Provider strategy: Gemini→Orchestrator, Claude→Agents, OpenAI gpt-5-nano→fallback

### Fase 2: Qualidade, Segurança e UI
- QA Auditor com Claude + Security Specialist + Performance Analyst
- Sentinel Self-Healing + Enlightn + Larastan + Nikto + SQLMap
- Filament v5 Dashboard + Circuit breakers + Git branching por task
- Laravel MCP servers para integração com agentes externos

### Fase 3: IA Avançada + Redes Sociais
- RAG Vetorial via pgvector nativo no PostgreSQL + Compressão de contexto (Ollama)
- Laravel Boost (guidelines, skills, documentation API)
- Multi-provider failover via `Lab` enum + Auto-alimentação de conhecimento
- SocialTool + `social_accounts` — publicação automática em 8 plataformas

---

## ⚡ Padrão de Desenvolvimento: Boost via MCP (Obrigatório)

**O Boost resolve — não apenas documenta.** O Laravel Boost tem toda a documentação do stack TALL mapeada e integrada. Quando recebe uma ação via MCP, retorna o código completo e correto para a versão instalada. **O agente não precisa conhecer Filament, Livewire ou Laravel** — ele descreve o problema de negócio, o Boost entrega o scaffold.

```
Agente recebe Sub-PRD → envia ação ao Boost via MCP → recebe código pronto → implementa
```

| Contexto | Como usar |
|---|---|
| **Agentes autônomos** | `SearchTool.boost_query` — o agente chama antes de qualquer implementação TALL |
| **Desenvolvimento manual** | `php artisan mcp:serve` — conectar Claude Code ao Boost antes de codar |

**Benefício real:** Zero tokens gastos com documentação de framework no contexto do agente. Cada token vai para a lógica de negócio, não para boilerplate.

Veja a seção **17. Laravel Boost + MCP** em `ARCHITECTURE.md` para o fluxo completo e como registrar padrões do projeto como Guidelines.

---

## 🖥️ Servidor

- **Ubuntu 24.04 LTS** — 2 vCPUs, 8 GB RAM
- **IP:** 10.1.1.86 (Supreme)
- **Consumo total estimado:** ~3.4 GB RAM com todos os componentes rodando

---

## 📄 Licença

Projeto proprietário — AndradeItalo.ai © 2026
