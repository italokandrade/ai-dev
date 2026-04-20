# AI-Dev Core

Sistema agêntico de desenvolvimento autônomo de projetos, construído em Laravel 13 + Filament v5.

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
- **`laravel/boost` `^2.4`** *(dev)* — Servidor MCP com ferramentas para inspeção do app (`database-schema`, `database-query`, `search-docs`, `browser-logs`, `last-error`). Usado pelo Claude Code no desenvolvimento.
- **`laravel/mcp` `^0.6`** — Protocolo MCP, dependência do Boost.

---

## Sistema Agêntico

### Agentes e Modelos

| Agent | Função | Provider | Modelo |
|---|---|---|---|
| `OrchestratorAgent` | Decompõe PRD em Sub-PRDs atômicos | openrouter | `anthropic/claude-opus-4-7` |
| `SpecificationAgent` | Transforma descrição informal em spec técnica JSON | openrouter | `anthropic/claude-opus-4-7` |
| `QuotationAgent` | Estima horas e custos por área profissional | openrouter | `anthropic/claude-opus-4-7` |
| `RefineDescriptionAgent` | Refina a descrição do projeto antes da especificação | openrouter | `anthropic/claude-opus-4-7` |
| `SpecialistAgent` | Lê e escreve código — implementa o Sub-PRD | openrouter | `anthropic/claude-sonnet-4-6` |
| `QAAuditorAgent` | Audita o código entregue e aprova ou rejeita | openrouter | `anthropic/claude-sonnet-4-6` |
| `DocsAgent` | Busca documentação TALL Stack via BoostTool | openrouter | `anthropic/claude-haiku-4-5-20251001` |

### Regra obrigatória — BoostTool antes de escrever código

`SpecialistAgent` e `QAAuditorAgent` têm o `BoostTool` como primeira tool (`app/Ai/Tools/BoostTool.php`). **Nenhum desses agentes deve escrever ou auditar código sem antes consultar o BoostTool** para verificar schema do banco (`database-schema`) e documentação correta (`search-docs`).

### Tools disponíveis por agent

| Agent | Tools |
|---|---|
| `SpecialistAgent` | BoostTool, DocSearchTool, ShellExecuteTool, FileReadTool, FileWriteTool, GitOperationTool |
| `QAAuditorAgent` | BoostTool |
| `DocsAgent` | BoostTool (search-docs) |

---

## Provedores de IA (`config/ai.php`)

Todo o sistema agêntico usa um único provider externo: **OpenRouter** com família Anthropic.

| Provider | Driver | Uso |
|---|---|---|
| `openrouter` | openai (compatível) | Todos os agentes — família Anthropic (Opus / Sonnet / Haiku) |
| `ollama` | ollama | ContextCompressor local — `qwen2.5:0.5b` (Fase 3, sem custo API) |
| `gemini` | anthropic | Proxy local :8001 — infraestrutura, reservado para uso futuro |
| `claude` | anthropic | Proxy local :8002 — infraestrutura, reservado para uso futuro |

**Documentação TALL Stack:** disponível via Laravel Boost MCP (`search-docs`). O `DocsAgent` usa `BoostTool` para consultar as docs sem vector store externo. Para RAG semântico futuro: pgvector nativo + Ollama (Fase 3).

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

- **`ProjectModuleResource`** — decomposição de projetos em módulos hierárquicos
- **`TaskResource`** — unidade de trabalho dos agentes; armazena PRD JSON e controla ciclo de vida
- **`AgentConfigResource`** — configuração de system_prompt, model e temperature por agente
- **`ProjectQuotationResource`** — comparação de custo humano vs. AI-Dev; rastreia consumo de tokens

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

*Atualizado em 19/04/2026*
