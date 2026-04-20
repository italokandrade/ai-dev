# Catálogo de Ferramentas (The Tool Layer)

## Contexto de Execução: sempre o Projeto Alvo

**Antes de ler qualquer ferramenta abaixo:** toda ferramenta é executada pelos agentes do **ai-dev-core** (Master), mas o **efeito** de todas as ferramentas que tocam filesystem, shell, git ou browser-logs acontece **dentro do Projeto Alvo** — nunca no filesystem ou banco do ai-dev-core. Para a separação canônica entre as duas camadas, consulte `README.md → Arquitetura em Duas Camadas`.

| Ferramenta | Escopo de execução |
|---|---|
| `ShellExecuteTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool` | `working_directory` = `projects.local_path` do alvo (injetado no constructor). |
| `BoostTool` | Envelopa as tools do Laravel Boost MCP (`search-docs`, `database-schema`, `database-query`, `browser-logs`, `last-error`) — devem ser resolvidas **no Boost do Projeto Alvo**. *(Implementation status: a versão atual ainda opera no ai-dev-core; tornar project-path-aware está na Fase 1 do roadmap. Prompts já assumem o estado-alvo.)* |
| `DocSearchTool` | Wrapper fino sobre `DocsAgent`, que por sua vez usa `BoostTool.search-docs`. Mesmo contexto do `BoostTool`. |

**Consequência prática para o engenheiro de prompt:** ao descrever uma ação para a IA, nunca cite o caminho do ai-dev-core como se fosse o palco do trabalho. O agente recebe `project.local_path` como input — todos os caminhos absolutos que ele manipula devem começar com esse prefixo.

---

## Visão Geral

O AI-Dev adota o **Padrão de Injeção de Comandos (Command-Injection Pattern)**. O ecossistema possui **6 ferramentas atômicas** implementando o contrato `Laravel\Ai\Contracts\Tool`. A IA gera apenas os **parâmetros e dados brutos** — as ferramentas executam os comandos no servidor de forma controlada, com logs, timeouts, pattern-blocklists e limites de tamanho.

**Por que 6 ferramentas?** O contrato `Tool` do Laravel AI SDK encoraja ferramentas enxutas com `schema()` preciso — cada Tool é uma classe PHP com ações bem definidas. Ferramentas especializadas (testes, segurança, performance, deploy, redes sociais) são cobertas pela combinação `ShellExecuteTool` + `BoostTool` no Projeto Alvo: `php artisan test`, `php artisan enlightn`, `composer audit`, `phpstan` rodam via shell; schema e logs vêm do Boost. Funcionalidades futuras (publicação em redes sociais, análise dinâmica) serão adicionadas como **Agents** dedicados, não como Tools genéricas.

**Arquitetura das Ferramentas:**
Cada ferramenta é uma classe PHP em `app/Ai/Tools/` que implementa o contrato `Tool` do **Laravel AI SDK** (`laravel/ai`):

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ShellExecuteTool implements Tool
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function description(): Stringable|string { /* ... */ }
    public function schema(JsonSchema $schema): array { /* ... */ }
    public function handle(Request $request): Stringable|string { /* ... */ }
}
```

O **Laravel AI SDK** despacha automaticamente as tool calls do LLM para o método `handle()` correto, validando os parâmetros contra o `schema()`. Se o schema falhar, o SDK retorna o erro ao LLM para que ele corrija — o LLM NUNCA interage com o sistema operacional diretamente.

As ferramentas que tocam filesystem/shell/git recebem `string $workingDirectory` no constructor, resolvido a partir de `projects.local_path` da task corrente. O `BoostTool` e o `DocSearchTool` não têm estado — a resolução de alvo é feita pela instância do Boost MCP consultada.

---

## 1. BoostTool — Ponte para o Laravel Boost MCP

**Classe:** `App\Ai\Tools\BoostTool`
**Constructor:** sem argumentos (resolve internamente via `app()`).
**Responsabilidade:** acesso unificado ao **Laravel Boost MCP** do Projeto Alvo para schema, queries, docs e logs. É a **primeira tool obrigatória** no pipeline do `SpecialistAgent` e do `QAAuditorAgent` — nenhum código é escrito ou auditado sem antes consultar o Boost.

### Sub-tools disponíveis (campo `tool`)

| `tool` | Descrição | Argumentos chave |
|---|---|---|
| `search-docs` | Busca na documentação da stack TALL do alvo (Laravel, Livewire, Filament, Tailwind, Alpine, Anime.js) — com versão correspondente à instalada no alvo | `queries: string[]` |
| `database-schema` | Retorna colunas, tipos, índices, FKs de uma tabela do alvo | `table: string` |
| `database-query` | Executa SELECT no banco do alvo (sem DDL/DML destrutivo) | `query: string`, `limit: int` |
| `browser-logs` | Últimos logs capturados pelo Telescope/Debugbar do alvo | `limit: int` |
| `last-error` | Última exceção registrada em `storage/logs` do alvo | — |

### Exemplo de chamada

```json
{
  "tool": "database-schema",
  "arguments": { "table": "users" }
}
```

```json
{
  "tool": "search-docs",
  "arguments": { "queries": ["Filament 5 table filters", "Livewire 4 lazy loading"] }
}
```

### Quando usar
- **Sempre antes de escrever código:** confirma que o schema e os nomes de classes/métodos existem na versão exata do alvo.
- **Sempre antes de auditar código:** o `QAAuditorAgent` valida que o padrão gerado bate com a doc oficial da versão instalada.
- **Nunca** para buscar docs do ai-dev-core (mesmo que ambos usem Laravel 13 e Filament 5 — o BoostTool do alvo reflete exatamente as versões `composer.lock` do alvo).

### Hardening do `database-query` *(Fase 2 — a implementar)*

A sub-tool `database-query` aceita atualmente SQL livre (`query: string`). Antes de ir para produção, ela deve receber as seguintes proteções, alinhadas com o padrão "Production-Safe Database Tools" do Laravel AI SDK blog:

#### Allowlist de tabelas, colunas e operadores

O handler deve rejeitar qualquer tabela ou coluna que não conste em allowlists explícitas, definidas no próprio código (não configuráveis pelo LLM):

```php
private array $TABLE_ALLOWLIST = [
    'orders', 'subscriptions', 'invoices', 'users', /* tabelas do Projeto Alvo */
];

private array $COLUMN_ALLOWLIST = [
    'orders'        => ['id', 'user_id', 'status', 'total', 'created_at'],
    'subscriptions' => ['id', 'user_id', 'plan', 'status', 'renews_at'],
    // ...
];

private array $OPERATOR_ALLOWLIST = ['=', '>', '<', '>=', '<=', 'LIKE', 'BETWEEN'];
```

O schema da sub-tool deve ser migrado de `query: string` para um objeto estruturado (`table`, `columns`, `where`) — o mesmo padrão do blog — de forma que o LLM nunca passe SQL raw, apenas intenções validadas.

#### Redação de dados sensíveis

Qualquer campo cujo nome termine em `_token`, `_secret`, `_password` ou `_key` deve ser substituído por `[REDACTED]` nos resultados antes de retornar ao LLM:

```php
private array $SENSITIVE_SUFFIXES = ['_token', '_secret', '_password', '_key', '_hash'];

private function redactSensitiveFields(array $rows): array
{
    foreach ($rows as &$row) {
        foreach ($row as $col => $val) {
            foreach ($this->SENSITIVE_SUFFIXES as $suffix) {
                if (str_ends_with($col, $suffix)) {
                    $row[$col] = '[REDACTED]';
                }
            }
        }
    }
    return $rows;
}
```

#### Conexão read-only obrigatória

Toda query do `database-query` deve usar a connection `readonly` (usuário PostgreSQL com permissão `SELECT`-only), nunca a connection padrão de escrita:

```php
DB::connection('readonly')->select($query, $bindings)
```

Configurar em `config/database.php` do ai-dev-core com as credenciais de leitura do Projeto Alvo.

#### Cap de saída (8 000 chars com slicing proporcional)

O resultado JSON não deve ultrapassar 8 000 caracteres. Se ultrapassar, fatiar proporcionalmente (estimar linhas que cabem, cortar, tentar novamente ao meio se ainda não couber):

```php
private function capOutput(array $rows, int $maxChars = 8000): string
{
    $encoded = json_encode($rows);
    if (strlen($encoded) <= $maxChars) {
        return $encoded;
    }
    $perRow = strlen($encoded) / count($rows);
    $count  = max(1, (int) ($maxChars / $perRow));
    do {
        $result = json_encode(array_slice($rows, 0, $count));
        $count  = (int) ($count / 2);
    } while (strlen($result) > $maxChars && $count > 0);
    return $result;
}
```

**Referência:** [Laravel AI SDK — Building Production-Safe Database Tools for Agents](https://laravel.com/blog/laravel-ai-sdk-building-production-safe-database-tools-for-agents)

---

## 2. DocSearchTool — Busca Focada em Docs TALL

**Classe:** `App\Ai\Tools\DocSearchTool`
**Constructor:** sem argumentos.
**Responsabilidade:** wrapper sobre o `DocsAgent` (Haiku 4.5) que delega para `BoostTool.search-docs`. Expõe um schema mais simples que o `BoostTool` completo quando o agente só precisa de docs.

### Schema

```json
{ "query": "Filament 5 BadgeColumn colors" }
```

### Quando usar
- Quando o agente só quer **uma resposta em texto natural** sobre a API correta — sem decidir entre as 5 sub-tools do Boost.
- Em tasks de frontend/Filament onde o agente iteraria várias vezes sobre `search-docs`: o `DocSearchTool` consolida em uma chamada.

### Diferença em relação ao `BoostTool.search-docs` direto
`BoostTool.search-docs` retorna JSON bruto das queries. `DocSearchTool` passa pela inferência do `DocsAgent`, que resume e formata — menos tokens de entrada no prompt do agente chamador.

---

## 3. FileReadTool — Leitura de Arquivos

**Classe:** `App\Ai\Tools\FileReadTool`
**Constructor:** `string $workingDirectory` (= `projects.local_path`).
**Responsabilidade:** ler arquivos do Projeto Alvo com paginação (`offset`/`limit`) ou listar um diretório.

### Schema

| Campo | Tipo | Descrição |
|---|---|---|
| `path` | string | Absoluto ou relativo ao `workingDirectory` |
| `offset` | int | Linha inicial (default 0) |
| `limit` | int | Máximo de linhas (default 500, max 2000) |

### Comportamento
- Se o `path` for um diretório, retorna a listagem (`type: directory`, `entries: [{name, type}]`).
- Se for um arquivo, retorna o trecho de linhas pedido + `total_lines` para o agente saber se precisa paginar.
- **Nunca** lê fora do alvo: paths relativos são sempre resolvidos contra o `workingDirectory`.

### Boas práticas
- Se você já sabe o intervalo relevante, passe `offset`/`limit` — nunca leia um arquivo de 2000 linhas quando precisa só de 40.
- Chame `FileReadTool` **em paralelo** quando precisar ler múltiplos arquivos: são 5 tool-calls na mesma resposta, não 5 turns.

---

## 4. FileWriteTool — Escrita, Patch e Criação de Diretórios

**Classe:** `App\Ai\Tools\FileWriteTool`
**Constructor:** `string $workingDirectory`.
**Responsabilidade:** criar/sobrescrever arquivos, aplicar substituições cirúrgicas (find & replace único) ou criar diretórios.

### Schema

| Campo | Tipo | Uso |
|---|---|---|
| `action` | enum `write`/`replace`/`mkdir` | — |
| `path` | string | Absoluto ou relativo ao `workingDirectory` |
| `content` | string | Conteúdo completo (para `write`) |
| `old_string` | string | Trecho exato a substituir (para `replace`, deve ser **único** no arquivo) |
| `new_string` | string | Substituição (para `replace`) |

### Garantias
- `replace` falha se `old_string` não for único — o agente precisa ampliar o contexto do trecho para desambiguar. Esta invariante previne substituições em múltiplos lugares sem querer.
- `write` cria diretórios intermediários (mode 0755) se necessário.
- Logs de `Log::info()` em cada operação — rastreáveis no `storage/logs/laravel.log` do ai-dev-core.

### Boas práticas
- Para **editar** arquivos existentes: use `replace` sempre que o trecho for único. Usa centenas de tokens a menos que reescrever o arquivo inteiro.
- Para **criar** arquivos novos: use `write`.
- Nunca use `write` sobre um arquivo existente se o que você quer é uma edição parcial — você perde o resto do conteúdo se o prompt estiver incompleto.

---

## 5. GitOperationTool — Operações Git

**Classe:** `App\Ai\Tools\GitOperationTool`
**Constructor:** `string $workingDirectory`.
**Responsabilidade:** operar o git do Projeto Alvo — status, diff, branches, staging, commit, push.

### Ações suportadas (campo `action`)

| Ação | Comando executado |
|---|---|
| `status` | `git status --short` |
| `diff` | `git diff` |
| `log` | `git log --oneline -20` |
| `branch_create` | `git checkout -b <branch>` |
| `branch_checkout` | `git checkout <branch>` |
| `branch_list` | `git branch -a` |
| `add` | `git add <path>` (ou `-A` se vazio) |
| `commit` | `git commit -m <message>` |
| `push` | `git push origin HEAD` |
| `reset_hard` | `git reset --hard HEAD` |
| `stash` | `git stash` |

### Garantias
- Todos os argumentos do usuário passam por `escapeshellarg()` antes de entrar na linha de comando.
- Timeout de 60s por operação.
- Saída truncada em 20 KB (stdout) e 5 KB (stderr) — evita estourar a janela do LLM.

### Uso típico do pipeline
```
GitOperationTool(action=branch_create, branch=task/a1b2c3d4)
... trabalho dos specialists ...
GitOperationTool(action=status)
GitOperationTool(action=diff)
GitOperationTool(action=add)   ... -A por default
GitOperationTool(action=commit, message='feat: criar UserResource Filament')
GitOperationTool(action=push)
```

---

## 6. ShellExecuteTool — Execução de Shell Controlada

**Classe:** `App\Ai\Tools\ShellExecuteTool`
**Constructor:** `string $workingDirectory`.
**Responsabilidade:** executar `php artisan …`, `composer …`, `npm …`, `php -l`, scripts auxiliares do alvo — com timeout, padrões bloqueados e saída truncada.

### Schema

| Campo | Tipo | Descrição |
|---|---|---|
| `command` | string | Comando completo (use caminhos absolutos) |
| `timeout` | int (1–600) | Timeout em segundos (default 120) |

### Padrões bloqueados (blocklist literal — não regex)
`rm -rf /`, `shutdown`, `reboot`, `chmod 777`, `chown root`, `mkfs`, `dd if=`, `> /dev/sda`, `format c:`. Qualquer comando contendo esses fragmentos é rejeitado sem execução.

### Retorno
```json
{
  "success": true,
  "exit_code": 0,
  "stdout": "…até 50 KB…",
  "stderr": "…até 10 KB…",
  "execution_time_ms": 4312
}
```

### Cobre os casos de uso de "TestTool", "SecurityTool", "DevOpsTool"
Os domínios especializados (teste, segurança, deploy, performance) são alcançados **combinando** `ShellExecuteTool` com `BoostTool`:

| Necessidade | Como é alcançada hoje |
|---|---|
| Rodar suite de testes | `ShellExecuteTool: php artisan test --compact` |
| Detectar vulnerabilidades | `ShellExecuteTool: php artisan enlightn`, `composer audit`, `npm audit`, `./vendor/bin/phpstan` |
| Verificar migrations pendentes | `ShellExecuteTool: php artisan migrate:status` |
| Ver stack trace mais recente | `BoostTool.last-error` |
| Ver queries lentas / N+1 | `BoostTool.browser-logs` (Telescope/Debugbar do alvo) |
| Schema real do banco do alvo | `BoostTool.database-schema` |

Nenhum dos itens acima justifica uma Tool dedicada: o LLM monta a invocação correta sozinho, e a blocklist do `ShellExecuteTool` + escopo do `workingDirectory` já cobrem os riscos.

---

## Distribuição das Tools por Agent

| Agent | Modelo (via OpenRouter) | Tools disponíveis |
|---|---|---|
| `OrchestratorAgent`, `SpecificationAgent`, `QuotationAgent`, `RefineDescriptionAgent` | **Opus 4.7** | — *(só planejamento/escrita JSON — não usam tools)* |
| `SpecialistAgent` (backend, frontend, filament, database, devops, security, performance) | **Sonnet 4.6** | `BoostTool`, `DocSearchTool`, `ShellExecuteTool`, `FileReadTool`, `FileWriteTool`, `GitOperationTool` |
| `QAAuditorAgent` | **Sonnet 4.6** | `BoostTool` *(auditoria sem executar código)* |
| `DocsAgent` | **Haiku 4.5** | `BoostTool.search-docs` |
| `ContextCompressor` *(Fase 3)* | **Ollama local** (`qwen2.5:0.5b`) | — *(recebe texto, retorna resumo — sem tool calls)* |

---

## Resumo Visual: Necessidade → Tool

```
Preciso ler um arquivo do alvo        → FileReadTool
Preciso editar um arquivo do alvo     → FileWriteTool (action=replace)
Preciso criar um arquivo novo         → FileWriteTool (action=write)
Preciso criar um diretório            → FileWriteTool (action=mkdir)
Preciso rodar artisan/composer/npm    → ShellExecuteTool
Preciso do schema do banco do alvo    → BoostTool (tool=database-schema)
Preciso pesquisar docs TALL           → DocSearchTool
Preciso rodar SELECT no banco do alvo → BoostTool (tool=database-query)
Preciso ver último erro do alvo       → BoostTool (tool=last-error)
Preciso commitar/enviar para o repo   → GitOperationTool
```

Se a necessidade não aparece acima, **ela não é uma Tool** — é responsabilidade de um Agent (planejamento, decisão, redação) ou ainda não foi implementada (publicação em redes sociais, análise de performance avançada — ver ARCHITECTURE.md §10 roadmap).
