# AI-Dev Core

Sistema agêntico de desenvolvimento autônomo de projetos, construído em Laravel 13 + Filament v5.

> **Posição na arquitetura:** este app é o **Master** (ai-dev-core). Ele **não hospeda** os Projetos Alvo — apenas os orquestra. Cada Projeto Alvo é uma aplicação Laravel independente (repositório próprio, banco próprio, Boost MCP próprio), registrada na tabela `projects` via `local_path`. Os agentes de desenvolvimento listados abaixo vivem aqui, mas **operam sobre o filesystem, git, banco e Boost do Projeto Alvo**. Para a separação canônica Master ↔ Alvo, ver `../README.md → Arquitetura em Duas Camadas`.

---

## Regra de Documentação para IAs

**Não criar `CLAUDE.md`, `GEMINI.md` ou qualquer arquivo de configuração específico por ferramenta de IA.**
Este `README.md` é a única fonte de verdade do projeto. Ferramentas de IA devem ler este arquivo para entender o projeto. O `CLAUDE.md` e o `GEMINI.md` são gerados automaticamente pelo `php artisan boost:install` — se existirem, ignore-os e consulte este arquivo.

---

## Stack

- **PHP:** 8.3
- **Framework:** Laravel 13
- **Admin UI:** Filament v5
- **Frontend:** Livewire 4 + Alpine.js v3 + Tailwind CSS v4
- **Banco:** PostgreSQL 16
- **Cache/Queue:** Redis 7
- **Testes:** Pest v4 + PHPUnit v12

---

## Pacotes de IA

- **`laravel/ai` `^0.5`** — SDK oficial Laravel: Agents, Tools, Embeddings, Vector Stores, Structured Output, Streaming.
- **`laravel/boost` `^2.4`** *(dev)* — Servidor MCP com ferramentas para inspeção do app (`database-schema`, `database-query`, `search-docs`, `browser-logs`, `last-error`). Instalado **dos dois lados**: aqui no ai-dev-core (para Claude Code humano editar o Master) e em cada Projeto Alvo (consumido pelo `BoostTool` dos agentes deste app para ler schema/docs do alvo).
- **`laravel/mcp` `^0.6`** — Protocolo MCP, dependência do Boost.

---

## Sistema Agêntico

### Agentes e Modelos

| Agent | Função | Provider | Modelo |
|---|---|---|---|
| `ProjectPrdAgent` | Gera PRD Master do projeto (módulos de alto nível) | Dinâmico (`LEVEL_PREMIUM`) | Dinâmico |
| `ModulePrdAgent` | Gera PRD Técnico de um módulo/submódulo | Dinâmico (`LEVEL_PREMIUM`) | Dinâmico |
| `GenerateFeaturesAgent` | Gera features backend/frontend por camada | Dinâmico (`LEVEL_PREMIUM`) | Dinâmico |
| `RefineDescriptionAgent` | Refina descrição do projeto com IA | Dinâmico (`LEVEL_PREMIUM`) | Dinâmico |
| `RefineFeatureAgent` | Refina feature individual com IA | Dinâmico (`LEVEL_PREMIUM`) | Dinâmico |
| `OrchestratorAgent` | Decompõe PRD em Sub-PRDs atômicos (execução de código) | openrouter | `anthropic/claude-opus-4.7` |
| `SpecificationAgent` | Transforma descrição informal em spec técnica JSON (legado) | openrouter | `anthropic/claude-opus-4.7` |
| `QuotationAgent` | Estima horas e custos por área profissional | openrouter | `anthropic/claude-opus-4.7` |
| `SpecialistAgent` | Lê e escreve código — implementa o Sub-PRD | openrouter | `anthropic/claude-sonnet-4-6` |
| `QAAuditorAgent` | Audita o código entregue e aprova ou rejeita | openrouter | `anthropic/claude-sonnet-4-6` |
| `DocsAgent` | Busca documentação TALL Stack via BoostTool | openrouter | `anthropic/claude-haiku-4-5-20251001` |

**Configuração de provider por agente:** todos os agentes de geração de PRD/features usam `AiRuntimeConfigService::LEVEL_PREMIUM`, configurável via SystemSettingsPage (provider + modelo + API key por nível). Não há provider hardcoded nesses agentes.

**Compatibilidade Kimi:** `ModulePrdAgent` e `ProjectPrdAgent` implementam `HasProviderOptions`. Quando o provider configurado for `kimi`, enviam automaticamente `max_completion_tokens: 32768` e `response_format: json_object` para contornar o default de 1024 tokens da API Moonshot.

### Regra obrigatória — BoostTool antes de escrever código

`SpecialistAgent` e `QAAuditorAgent` têm o `BoostTool` como primeira tool (`app/Ai/Tools/BoostTool.php`). **Nenhum desses agentes deve escrever ou auditar código sem antes consultar o BoostTool** para verificar schema do banco (`database-schema`) e documentação correta (`search-docs`) — sempre **no Boost do Projeto Alvo** da task atual (resolvido via `projects.local_path`), nunca no Boost deste ai-dev-core.

### Tools disponíveis por agent

| Agent | Tools |
|---|---|
| `SpecialistAgent` | BoostTool, DocSearchTool, ShellExecuteTool, FileReadTool, FileWriteTool, GitOperationTool |
| `QAAuditorAgent` | BoostTool |
| `DocsAgent` | BoostTool (search-docs) |

> Todas as tools de filesystem/shell/git (`ShellExecuteTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool`) operam com `working_directory = projects.local_path` do Projeto Alvo da task — **nunca** sobre o próprio ai-dev-core. Ver `../FERRAMENTAS.md → Contexto de Execução`.

---

## Provedores de IA (`config/ai.php`)

Todo o sistema agêntico usa um único provider externo: **OpenRouter** com família Anthropic.

| Provider | Driver | Uso |
|---|---|---|
| `openrouter` | openai (compatível) | Todos os agentes — família Anthropic (Opus / Sonnet / Haiku). Default global (`config/ai.php → default`). |
| `openrouter_chain` | failover | Alias para failover `openrouter → openai` quando declarado explicitamente no agente |
| `ollama` | ollama | ContextCompressor local — `qwen2.5:0.5b` (Fase 3, sem custo API) |

**Documentação TALL Stack:** disponível via Laravel Boost MCP (`search-docs`), consultada **no Boost do Projeto Alvo** — assim o `DocsAgent` devolve a versão exata das libs instaladas naquele alvo (não as libs do Master). Sem vector store externo. Para RAG semântico futuro: pgvector nativo + Ollama (Fase 3).

---

## Testes

```bash
php artisan test --compact                        # rodar todos
php artisan test --compact --filter=testName     # filtrar
php artisan make:test --pest NomeDoTeste         # criar
```

- Framework: Pest v4 + `pestphp/pest-plugin-laravel`
- Bootstrap customizado: `tests/bootstrap.php` (necessário pois Composer desabilita plugins quando executado como root)
- Configuração global: `pest.php` na raiz — aplica `TestCase + RefreshDatabase` em todos os Feature tests

---

## Componentes Filament (UI administrativa)

- **`ProjectResource`** — CRUD de projetos + fluxo de PRD Master + aba Módulos hierárquica com submódulos colapsáveis
- **`ProjectModuleResource`** — visualização e ações de módulos/submódulos; oculto do sidebar (`$shouldRegisterNavigation = false`), acessado via links da aba Módulos
- **`TaskResource`** — unidade de trabalho dos agentes; armazena PRD JSON e controla ciclo de vida
- **`AgentConfigResource`** — configuração de system_prompt, model e temperature por agente
- **`ProjectQuotationResource`** — comparação de custo humano vs. AI-Dev; rastreia consumo de tokens

### Fluxo de PRD em Cascata (Auto Aprovação)

O botão **"Auto Aprovar PRD — Cascata Completa"** em `ViewProject` dispara o `CascadeModulePrdJob` recursivamente:

1. Aprova o PRD Master do projeto e cria módulos raiz
2. Para cada módulo raiz, despacha `CascadeModulePrdJob`
3. O job gera o PRD técnico do módulo via `ModulePrdAgent`
4. Se `needs_submodules: true` → cria submódulos e despacha `CascadeModulePrdJob` para cada um
5. Se `needs_submodules: false` → cria tasks (status `pending` — **nunca executadas automaticamente**)
6. O processo se repete recursivamente até todas as folhas terem tasks

**Resiliência:** se o job já encontrar um PRD válido salvo, pula a geração e vai direto para auto-aprovação. Não duplica submódulos ou tasks se já existirem. O `failed()` só salva fallback se não houver PRD válido já gravado.

### Estados dos botões de PRD (ViewProject e ViewProjectModule)

| Estado | Botão exibido |
|---|---|
| Sem PRD / falhou | "Gerar PRD" (cinza) |
| `_status: generating` | "Gerando PRD..." (cinza, desabilitado) |
| PRD pronto, não aprovado | "Ver PRD Completo" + "Aprovar PRD" + "Auto Aprovar PRD — Cascata Completa" |
| PRD aprovado | "Ver PRD Completo" |

### Paleta de cores (Enums)

Sistema de cores uniformizado — apenas 4 cores semânticas:
- `gray` → estados dormentes (planejado, pausado, em teste, pendente)
- `primary` (azul) → estado ativo / em progresso
- `success` (verde) → concluído
- `danger` (vermelho) → erro / falha / rejeitado

---

## Estrutura de Pastas

```
app/Ai/Agents/     — classes de agentes (Promptable + atributos de provider/model)
app/Ai/Tools/      — tools customizadas (BoostTool, DocSearchTool, FileReadTool, etc.)
app/Ai/Providers/  — providers customizados (FailoverProvider)
app/Filament/      — UI administrativa
app/Models/        — Eloquent models com HasUuids
app/Enums/         — enums de status e prioridades
database/migrations/
tests/Feature/
```

---

## Convenções

- PHP 8.3: constructor property promotion, return types explícitos, enums com TitleCase
- Models: `HasUuids` + `uuid('id')->primary()`
- Sempre rodar `vendor/bin/pint --dirty --format agent` após modificar PHP
- Usar `php artisan make:` para criar arquivos novos
- Nunca usar `env()` fora de `config/`
- Commits: `feat: ...`, `fix: ...`, `refactor: ...`

---

*Atualizado em 24/04/2026*
