# Catálogo de Ferramentas Consolidadas (The Tool Layer)

O AI-Dev adota o **Padrão de Injeção de Comandos (Command-Injection Pattern)**. O ecossistema possui **10 ferramentas atômicas** que cobrem 100% das necessidades de um desenvolvedor Fullstack TALL + DBA + Security + Social Media. A IA gera apenas os **parâmetros e dados brutos** — as ferramentas executam os comandos no servidor de forma controlada, com logs, timeouts e restrições de segurança.

**Por que 10 ferramentas e não 18+?** Modelos de linguagem consomem tokens processando a lista de ferramentas disponíveis. Com 18+ ferramentas de nomes parecidos (FileArchitectTool vs FileSystemNavigatorTool vs FileSurgeryTool), a IA gasta mais tempo "decidindo" qual usar e comete mais erros na seleção. Com 10 ferramentas consolidadas e sub-ações claras (ex: `FileTool.action = "read"` vs `FileTool.action = "write"`), a decisão é rápida e precisa.

**Arquitetura das Ferramentas:**
Cada ferramenta é uma classe PHP em `app/Ai/Tools/` que implementa o contrato `Tool` do **Laravel AI SDK** (`laravel/ai`):

```php
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ShellTool implements Tool
{
    public function description(): string       // Descrição para o LLM
    {
        return 'Execute shell commands on the server in a controlled way.';
    }

    public function schema(JsonSchema $schema): array  // Parâmetros de entrada (JsonSchema builder)
    {
        return [
            'action'  => $schema->string()->enum(['execute', 'execute_background', 'kill']),
            'command' => $schema->string()->description('Full command with absolute paths'),
        ];
    }

    public function handle(Request $request): string   // Execução real
    {
        // $request->get('action'), $request->get('command')
    }
}
```

O **Laravel AI SDK** despacha automaticamente as tool calls do LLM para o método `handle()` correto, validando os parâmetros contra o `schema()`. Se o schema falhar, o SDK retorna o erro ao LLM para que ele corrija — o LLM NUNCA interage com o sistema operacional diretamente.

---

## 1. ShellTool — Execução de Comandos no Terminal

**Classe:** `App\Tools\ShellTool`
**Responsabilidade:** Executar qualquer comando no terminal do servidor de forma controlada. Substitui as antigas `TerminalExecutorTool`, `ArtisanPowerTool` e `AssetCompilerTool` em uma única ferramenta versátil.

**Por que consolidar?** O `ArtisanPowerTool` era literalmente um subset do terminal — `php artisan X` é um comando de terminal. O `AssetCompilerTool` (`npm run build`) também. Ter 3 ferramentas para "rodar comandos" confundia a IA.

### Ações Disponíveis

| Ação | Descrição | Exemplo |
|---|---|---|
| `execute` | Rodar um comando qualquer | `php artisan make:model Post -mfs` |
| `execute_background` | Rodar comando em background (não bloqueia) | `npm run build` |
| `kill` | Matar um processo em background pelo PID | Encerrar um `npm run dev` travado |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action", "command"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["execute", "execute_background", "kill"],
      "description": "Qual ação executar."
    },
    "command": {
      "type": "string",
      "description": "O comando completo a ser executado. DEVE usar caminhos absolutos. DEVE incluir flags --no-interaction ou -y quando possível para evitar travamento.",
      "examples": [
        "php artisan make:filament-resource User --generate",
        "cd /var/www/html/projetos/portal && npm run build",
        "php artisan migrate --force",
        "composer require spatie/laravel-permission --no-interaction"
      ]
    },
    "working_directory": {
      "type": "string",
      "description": "Diretório de trabalho. Se omitido, usa o local_path do projeto ativo.",
      "examples": ["/var/www/html/projetos/portal"]
    },
    "timeout_seconds": {
      "type": "integer",
      "description": "Timeout máximo em segundos. Se o comando não terminar nesse tempo, é morto. Padrão: 120 para execute, 600 para execute_background.",
      "default": 120,
      "minimum": 5,
      "maximum": 600
    },
    "pid": {
      "type": "integer",
      "description": "PID do processo a matar. Obrigatório apenas para action 'kill'."
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean", "description": "Se o comando terminou com exit code 0"},
    "exit_code": {"type": "integer", "description": "Código de saída do processo"},
    "stdout": {"type": "string", "description": "Saída padrão (stdout) do comando"},
    "stderr": {"type": "string", "description": "Saída de erro (stderr) do comando"},
    "pid": {"type": "integer", "description": "PID do processo (apenas para execute_background)"},
    "execution_time_ms": {"type": "integer", "description": "Tempo de execução em milissegundos"}
  }
}
```

### Restrições de Segurança

- **Comandos bloqueados:** `rm -rf /`, `shutdown`, `reboot`, `chmod 777`, `chown root`, qualquer comando com `|` pipe para `/dev/sda`, `mkfs`, `dd if=`.
- **Caminhos permitidos:** Apenas dentro de `/var/www/html/projetos/` — qualquer tentativa de operar fora é bloqueada.
- **Logs:** Todo comando executado é gravado em `tool_calls_log` com input, output, tempo e status.

---

## 2. FileTool — Manipulação de Arquivos e Diretórios

**Classe:** `App\Tools\FileTool`
**Responsabilidade:** Ler, criar, editar, renomear, mover e deletar arquivos. Navegar diretórios. Substitui as antigas `FileSurgeryTool`, `FileArchitectTool` e `FileSystemNavigatorTool`.

### Ações Disponíveis

| Ação | Descrição | Uso Prático |
|---|---|---|
| `read` | Ler conteúdo de um arquivo | Ler Model para entender a estrutura antes de editar |
| `write` | Criar ou sobrescrever arquivo inteiro | Criar um novo Controller, View, Migration |
| `patch` | Edição cirúrgica (Search & Replace) | Adicionar método num Model sem reescrever o arquivo inteiro |
| `insert` | Inserir conteúdo em posição específica | Adicionar `use SoftDeletes;` após `use HasFactory;` |
| `delete` | Deletar arquivo (com backup automático) | Remover arquivo de teste obsoleto |
| `rename` | Renomear/mover arquivo ou diretório | Refatorar nomes de classes |
| `list_dir` | Listar conteúdo de um diretório | Mapear a estrutura `app/Models/` |
| `tree` | Árvore completa do diretório (recursivo) | Entender a arquitetura do projeto inteiro |
| `exists` | Verificar se arquivo/diretório existe | Checar antes de criar para não sobrescrever |
| `permissions` | Ajustar chmod/chown | Garantir que `storage/` tenha escrita para www-data |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action", "path"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["read", "write", "patch", "insert", "delete", "rename", "list_dir", "tree", "exists", "permissions"],
      "description": "Qual ação executar no arquivo/diretório."
    },
    "path": {
      "type": "string",
      "description": "Caminho ABSOLUTO do arquivo ou diretório. DEVE começar com /var/www/html/projetos/.",
      "pattern": "^/var/www/html/projetos/",
      "examples": ["/var/www/html/projetos/portal/app/Models/User.php"]
    },
    "content": {
      "type": "string",
      "description": "Conteúdo do arquivo. Obrigatório para 'write'. Para 'write', este é o conteúdo COMPLETO do novo arquivo."
    },
    "search": {
      "type": "string",
      "description": "Texto a ser buscado (para 'patch'). Deve ser uma string EXATA que existe no arquivo."
    },
    "replace": {
      "type": "string",
      "description": "Texto que substituirá o 'search' (para 'patch'). Pode ser vazio para deletar o trecho."
    },
    "position": {
      "type": "string",
      "enum": ["before", "after", "line"],
      "description": "Onde inserir (para 'insert'): 'before' ou 'after' do anchor_text, ou numa 'line' específica."
    },
    "anchor_text": {
      "type": "string",
      "description": "Texto âncora para 'insert' com position 'before' ou 'after'. Ex: 'use HasFactory;'"
    },
    "line_number": {
      "type": "integer",
      "description": "Número da linha para 'insert' com position='line'. Linhas começam em 1."
    },
    "new_path": {
      "type": "string",
      "description": "Novo caminho para 'rename'. DEVE começar com /var/www/html/projetos/."
    },
    "chmod": {
      "type": "string",
      "description": "Permissões para 'permissions'. Ex: '755', '644'.",
      "pattern": "^[0-7]{3}$"
    },
    "chown": {
      "type": "string",
      "description": "Proprietário para 'permissions'. Permitido apenas 'www-data:www-data'.",
      "enum": ["www-data:www-data"]
    },
    "start_line": {
      "type": "integer",
      "description": "Para 'read': linha inicial (1-indexed). Se omitido, lê o arquivo inteiro.",
      "minimum": 1
    },
    "end_line": {
      "type": "integer",
      "description": "Para 'read': linha final (inclusive). Se omitido, lê até o final.",
      "minimum": 1
    },
    "max_depth": {
      "type": "integer",
      "description": "Para 'tree': profundidade máxima da recursão. Padrão: 3.",
      "default": 3,
      "minimum": 1,
      "maximum": 10
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "content": {"type": "string", "description": "Conteúdo do arquivo (para 'read') ou tree (para 'tree', 'list_dir')"},
    "lines_total": {"type": "integer", "description": "Total de linhas do arquivo (para 'read')"},
    "bytes": {"type": "integer", "description": "Tamanho em bytes do arquivo"},
    "matches_replaced": {"type": "integer", "description": "Quantas substituições foram feitas (para 'patch')"},
    "exists": {"type": "boolean", "description": "Se o arquivo/diretório existe (para 'exists')"},
    "backup_path": {"type": "string", "description": "Caminho do backup criado (para 'delete')"},
    "error": {"type": "string", "description": "Mensagem de erro se success=false"}
  }
}
```

### Exemplo Prático: Edição Cirúrgica (Patch)

Em vez de reescrever o arquivo inteiro (que consome tokens e pode perder conteúdo), a IA usa `patch`:

```json
{
  "action": "patch",
  "path": "/var/www/html/projetos/portal/app/Models/User.php",
  "search": "protected $fillable = [\n        'name',\n        'email',\n        'password',\n    ];",
  "replace": "protected $fillable = [\n        'name',\n        'email',\n        'password',\n        'avatar',\n        'phone',\n        'role',\n    ];"
}
```

**Por que isso é superior?** O Model User pode ter 200 linhas. Enviar as 200 linhas pelo LLM (write) consome ~600 tokens. Enviar apenas o trecho de 8 linhas (patch) consome ~24 tokens. Economia de 96%.

---

## 3. DatabaseTool — Gestão Completa de Banco de Dados

**Classe:** `App\Tools\DatabaseTool`
**Responsabilidade:** Manipulação DDL (estrutura) e DML (dados), manutenção do banco e geração de seeders. Substitui `SchemaManagerTool`, `DataManipulatorTool`, `DatabaseMaintenanceTool`, `SeederGeneratorTool` e `SchemaExplorerTool`.

### Ações Disponíveis

| Ação | Descrição | Uso Prático |
|---|---|---|
| `describe` | DESCRIBE de uma tabela (colunas, tipos, índices) | Conhecer a estrutura antes de criar queries |
| `query` | Executar SELECT (somente leitura) | Contar registros, verificar dados |
| `execute` | Executar INSERT/UPDATE/DELETE (escrita) | Popular dados, corrigir registros |
| `show_tables` | Listar todas as tabelas do banco | Mapear o schema do projeto |
| `show_create` | Mostrar o CREATE TABLE de uma tabela | Entender estrutura completa com índices e FKs |
| `dump` | Gerar dump SQL do banco ou de tabelas específicas | Backup antes de operações arriscadas |
| `optimize` | OPTIMIZE TABLE em tabelas específicas | Manutenção periódica |
| `migration_status` | Listar migrations rodadas e pendentes | Verificar estado do schema |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["describe", "query", "execute", "show_tables", "show_create", "dump", "optimize", "migration_status"]
    },
    "database": {
      "type": "string",
      "description": "Nome do banco de dados. Se omitido, usa o DB do projeto ativo (via .env do projeto).",
      "examples": ["portal_db"]
    },
    "table": {
      "type": "string",
      "description": "Nome da tabela. Obrigatório para describe, show_create, optimize.",
      "examples": ["users", "posts"]
    },
    "sql": {
      "type": "string",
      "description": "Query SQL para 'query' (apenas SELECT) ou 'execute' (INSERT/UPDATE/DELETE). Proibido DROP DATABASE, DROP TABLE sem WHERE, TRUNCATE sem confirmação.",
      "examples": [
        "SELECT id, name, email FROM users WHERE role = 'admin' LIMIT 10",
        "INSERT INTO tags (name, slug) VALUES ('Laravel', 'laravel')"
      ]
    },
    "tables": {
      "type": "array",
      "description": "Lista de tabelas para 'dump'. Se omitido no dump, faz dump do banco inteiro.",
      "items": {"type": "string"}
    },
    "output_path": {
      "type": "string",
      "description": "Caminho de saída para 'dump'. DEVE estar dentro de /var/www/html/projetos/",
      "examples": ["/var/www/html/projetos/portal/storage/backups/dump_2026-04-09.sql"]
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "data": {
      "type": "array",
      "description": "Resultados da query (para 'query'). Array de objetos JSON.",
      "items": {"type": "object"}
    },
    "columns": {
      "type": "array",
      "description": "Lista de colunas com tipos, nullable, default (para 'describe').",
      "items": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "type": {"type": "string"},
          "nullable": {"type": "boolean"},
          "default": {"type": "string"},
          "key": {"type": "string"}
        }
      }
    },
    "tables": {
      "type": "array",
      "description": "Lista de tabelas (para 'show_tables').",
      "items": {"type": "string"}
    },
    "create_statement": {"type": "string", "description": "CREATE TABLE completo (para 'show_create')"},
    "affected_rows": {"type": "integer", "description": "Linhas afetadas (para 'execute')"},
    "dump_path": {"type": "string", "description": "Caminho do dump gerado (para 'dump')"},
    "migrations": {
      "type": "array",
      "description": "Lista de migrations (para 'migration_status').",
      "items": {
        "type": "object",
        "properties": {
          "migration": {"type": "string"},
          "batch": {"type": "integer"},
          "ran": {"type": "boolean"}
        }
      }
    },
    "error": {"type": "string"}
  }
}
```

### Restrições de Segurança

- **Proibido:** `DROP DATABASE`, `DROP TABLE` sem confirmação explícita, `TRUNCATE` sem backup prévio.
- **Apenas bancos do projeto:** A ferramenta só acessa o banco configurado no `.env` do projeto ativo.
- **Query timeout:** SELECT com LIMIT obrigatório. Queries sem LIMIT recebem `LIMIT 1000` automaticamente.
- **Backup automático:** Antes de qualquer `execute` (INSERT/UPDATE/DELETE), a ferramenta faz um `SELECT` dos registros afetados e salva como backup no log.

---

## 4. GitTool — Controle de Versão e Integração GitHub

**Classe:** `App\Tools\GitTool`
**Responsabilidade:** Todas as operações Git locais e integração com API do GitHub. Substitui `GitMasterTool` e `GitHubIntegrationTool`.

### Ações Disponíveis

| Ação | Descrição | Uso |
|---|---|---|
| `status` | `git status` do repositório | Verificar arquivos modificados antes de commit |
| `diff` | `git diff` (staged, unstaged ou entre commits) | Revisar mudanças antes de commit |
| `add` | `git add` de arquivos específicos ou todos | Preparar para commit |
| `commit` | `git commit` com mensagem | Gravar alterações |
| `push` | `git push` para remote | Enviar para GitHub |
| `pull` | `git pull` do remote | Atualizar código local |
| `branch` | Criar, listar, trocar ou deletar branches | Isolamento por task |
| `merge` | Merge de branch | Integrar task ao main |
| `stash` | Salvar alterações temporárias | Pausar trabalho no meio |
| `revert` | Reverter um commit específico | Rollback de alteração problemática |
| `log` | Histórico de commits | Consultar alterações passadas |
| `github_api` | Acessar a API REST do GitHub | Ler issues, PRs, diffs de PRs |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["status", "diff", "add", "commit", "push", "pull", "branch", "merge", "stash", "revert", "log", "github_api"]
    },
    "repository_path": {
      "type": "string",
      "description": "Caminho do repositório. Se omitido, usa o local_path do projeto ativo."
    },
    "files": {
      "type": "array",
      "description": "Arquivos para 'add'. Se vazio ou omitido no add, faz 'git add .'.",
      "items": {"type": "string"}
    },
    "message": {
      "type": "string",
      "description": "Mensagem do commit. Obrigatório para 'commit'. Formato: 'tipo(escopo): descrição'.",
      "examples": ["feat(users): criar Resource de Gestão de Usuários"]
    },
    "branch_name": {
      "type": "string",
      "description": "Nome do branch para 'branch' (criar/trocar) ou 'merge' (branch origem).",
      "examples": ["task/a1b2c3d4", "feature/user-crud"]
    },
    "branch_action": {
      "type": "string",
      "enum": ["create", "switch", "delete", "list"],
      "description": "Sub-ação para 'branch'."
    },
    "commit_hash": {
      "type": "string",
      "description": "Hash do commit para 'revert' ou 'diff' entre commits."
    },
    "merge_no_ff": {
      "type": "boolean",
      "description": "Se true, usa --no-ff no merge (preserva histórico do branch). Padrão: true.",
      "default": true
    },
    "log_count": {
      "type": "integer",
      "description": "Número de commits para 'log'. Padrão: 10.",
      "default": 10,
      "minimum": 1,
      "maximum": 50
    },
    "github_endpoint": {
      "type": "string",
      "description": "Endpoint da API GitHub para 'github_api'. Ex: '/repos/{owner}/{repo}/issues'."
    },
    "github_method": {
      "type": "string",
      "enum": ["GET", "POST", "PATCH"],
      "description": "Método HTTP para 'github_api'. Padrão: GET.",
      "default": "GET"
    },
    "github_body": {
      "type": "object",
      "description": "Corpo da requisição para 'github_api' com método POST/PATCH."
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "output": {"type": "string", "description": "Saída do comando git"},
    "branch": {"type": "string", "description": "Branch atual após a operação"},
    "branches": {"type": "array", "description": "Lista de branches (para branch_action='list')"},
    "commits": {
      "type": "array",
      "description": "Lista de commits (para 'log').",
      "items": {
        "type": "object",
        "properties": {
          "hash": {"type": "string"},
          "author": {"type": "string"},
          "date": {"type": "string"},
          "message": {"type": "string"}
        }
      }
    },
    "github_response": {"type": "object", "description": "Resposta da API GitHub (para 'github_api')"},
    "error": {"type": "string"}
  }
}
```

---

## 5. SearchTool — Pesquisa Web e Scraping Inteligente

**Classe:** `App\Tools\SearchTool`
**Responsabilidade:** Pesquisa na web para encontrar soluções técnicas e raspagem inteligente de documentação. Substitui `DuckDuckGoSearchTool` e `FirecrawlScraperTool`.

### Ações Disponíveis

| Ação | Descrição | Uso |
|---|---|---|
| `search` | Pesquisa na web via DuckDuckGo | Encontrar solução para erro desconhecido |
| `scrape` | Raspar página web e converter para Markdown limpo | Ler documentação do Filament/Laravel |
| `grep_code` | Busca de padrões no código do projeto (grep/ripgrep) | Encontrar onde uma classe é usada |
| `find_files` | Busca de arquivos por padrão (glob) | Encontrar todos os Models, Controllers, etc. |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["search", "scrape", "grep_code", "find_files"]
    },
    "query": {
      "type": "string",
      "description": "Termo de busca para 'search'. Ou padrão regex/texto para 'grep_code'.",
      "examples": [
        "Filament v5 create resource with tabs",
        "Laravel 13 QueryException column not found",
        "class UserResource"
      ]
    },
    "url": {
      "type": "string",
      "description": "URL para 'scrape'. A página será convertida para Markdown puro.",
      "examples": ["https://filamentphp.com/docs/3.x/panels/resources/creating-records"]
    },
    "search_path": {
      "type": "string",
      "description": "Diretório para 'grep_code' e 'find_files'. DEVE ser caminhos do projeto.",
      "examples": ["/var/www/html/projetos/portal/app"]
    },
    "pattern": {
      "type": "string",
      "description": "Padrão glob para 'find_files'. Ex: '*.php', '*Resource*.php'.",
      "examples": ["*.php", "*Controller.php", "*.blade.php"]
    },
    "case_insensitive": {
      "type": "boolean",
      "description": "Para 'grep_code': busca case-insensitive. Padrão: false.",
      "default": false
    },
    "max_results": {
      "type": "integer",
      "description": "Máximo de resultados para 'search' e 'grep_code'. Padrão: 10.",
      "default": 10,
      "minimum": 1,
      "maximum": 50
    },
    "include_extensions": {
      "type": "array",
      "description": "Para 'grep_code': filtrar por extensão. Ex: ['php', 'blade.php'].",
      "items": {"type": "string"},
      "examples": [["php"], ["blade.php", "js"]]
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "results": {
      "type": "array",
      "description": "Resultados de 'search': título + URL + snippet.",
      "items": {
        "type": "object",
        "properties": {
          "title": {"type": "string"},
          "url": {"type": "string"},
          "snippet": {"type": "string"}
        }
      }
    },
    "markdown_content": {
      "type": "string",
      "description": "Conteúdo da página em Markdown puro (para 'scrape'). O HTML é convertido automaticamente."
    },
    "matches": {
      "type": "array",
      "description": "Resultados de 'grep_code': arquivo + linha + conteúdo.",
      "items": {
        "type": "object",
        "properties": {
          "file": {"type": "string"},
          "line": {"type": "integer"},
          "content": {"type": "string"}
        }
      }
    },
    "files": {
      "type": "array",
      "description": "Resultados de 'find_files': caminhos dos arquivos encontrados.",
      "items": {"type": "string"}
    },
    "error": {"type": "string"}
  }
}
```

### Segurança do Scraping

- **Context Threat Scanning:** Antes de injetar o Markdown raspado no prompt, o PromptFactory escaneia o conteúdo buscando padrões de prompt injection (ver `PROMPTS.md` seção 5).
- **Tamanho máximo:** O Markdown raspado é truncado em 8000 tokens para não explodir o contexto.
- **Retry com fallback:** Se o Firecrawl self-hosted falhar, tenta via DuckDuckGo Instant Answer como fallback.

---

## 6. TestTool — Controle de Qualidade e Testes Automatizados

**Classe:** `App\Tools\TestTool`
**Responsabilidade:** Executar testes automatizados (Pest/PHPUnit e Dusk), capturar screenshots de falhas visuais e analisar cobertura de código. Substitui `TestAutomatorTool`, `DuskSimulatorTool` e `VisionBrowserTool`.

### Ações Disponíveis

| Ação | Descrição | Uso |
|---|---|---|
| `run` | Executar suite de testes (Pest/PHPUnit) | Testar código após implementação |
| `run_filter` | Executar teste específico por classe ou método | Testar apenas o que foi alterado |
| `dusk` | Executar testes de browser (Laravel Dusk) | Testar fluxos visuais end-to-end |
| `screenshot` | Capturar screenshot de uma URL via headless browser | Verificar visualmente uma página |
| `coverage` | Gerar relatório de cobertura de código | Identificar código não testado |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["run", "run_filter", "dusk", "screenshot", "coverage"]
    },
    "project_path": {
      "type": "string",
      "description": "Caminho do projeto. Se omitido, usa o local_path do projeto ativo."
    },
    "filter": {
      "type": "string",
      "description": "Para 'run_filter': nome da classe ou método de teste.",
      "examples": ["UserResourceTest", "it_can_create_a_user"]
    },
    "parallel": {
      "type": "boolean",
      "description": "Para 'run': executar testes em paralelo. Padrão: true.",
      "default": true
    },
    "url": {
      "type": "string",
      "description": "Para 'screenshot': URL da página a capturar.",
      "examples": ["http://portal.test/admin/users"]
    },
    "screenshot_path": {
      "type": "string",
      "description": "Para 'screenshot': caminho onde salvar a imagem.",
      "examples": ["/var/www/html/projetos/portal/storage/screenshots/users_list.png"]
    },
    "viewport": {
      "type": "object",
      "description": "Para 'screenshot': tamanho do viewport.",
      "properties": {
        "width": {"type": "integer", "default": 1920},
        "height": {"type": "integer", "default": 1080}
      }
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "tests_total": {"type": "integer"},
    "tests_passed": {"type": "integer"},
    "tests_failed": {"type": "integer"},
    "tests_skipped": {"type": "integer"},
    "failures": {
      "type": "array",
      "description": "Detalhes de cada teste que falhou.",
      "items": {
        "type": "object",
        "properties": {
          "test_name": {"type": "string"},
          "message": {"type": "string"},
          "file": {"type": "string"},
          "line": {"type": "integer"},
          "diff": {"type": "string", "description": "Diff entre esperado e obtido (se assertion)"}
        }
      }
    },
    "output": {"type": "string", "description": "Saída completa do comando de teste"},
    "screenshot_path": {"type": "string", "description": "Caminho da screenshot gerada (para 'screenshot')"},
    "coverage_percent": {"type": "number", "description": "Porcentagem de cobertura (para 'coverage')"},
    "execution_time_seconds": {"type": "number"},
    "error": {"type": "string"}
  }
}
```

---

## 7. SecurityTool — Auditoria de Segurança e Pentest

**Classe:** `App\Tools\SecurityTool`
**Responsabilidade:** Executar verificações de segurança automatizadas no código e no servidor do projeto. Esta é a ferramenta exclusiva do `security-specialist` e também pode ser usada pelo `qa-auditor` para validações rápidas.

**Ferramentas Gratuitas Integradas:**
- **Enlightn OSS** — 66 checks automatizados de segurança/performance para Laravel (composer package)
- **Larastan/PHPStan** — Análise estática de tipos e bugs (composer package)
- **composer audit** — Verifica CVEs em dependências PHP (nativo do Composer 2.4+)
- **npm audit** — Verifica CVEs em dependências JavaScript (nativo do npm)
- **Nikto** — Scanner de servidor web (apt install nikto — 100% gratuito)
- **SQLMap** — Teste automatizado de SQL injection (pip install sqlmap — 100% gratuito, Python)

### Ações Disponíveis

| Ação | Descrição | Ferramenta Usada | Uso |
|---|---|---|---|
| `enlightn` | Rodar Enlightn Security Analysis | `php artisan enlightn` | Verificar 66 checks de segurança/performance Laravel |
| `static_analysis` | Rodar análise estática PHPStan/Larastan | `./vendor/bin/phpstan analyse` | Detectar bugs de tipo, variáveis undefined, chamadas inválidas |
| `dependency_audit` | Verificar CVEs em dependências | `composer audit` + `npm audit` | Detectar pacotes vulneráveis no composer.lock e package-lock.json |
| `server_scan` | Scan de servidor web | Nikto | Verificar headers, diretórios expostos, versões outdated |
| `sql_injection_test` | Testar SQL injection em formulários | SQLMap (modo seguro) | Verificar se formulários são vulneráveis a SQL injection |
| `full_audit` | Executar TODAS as ações acima em sequência | Todas | Auditoria completa de segurança |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["enlightn", "static_analysis", "dependency_audit", "server_scan", "sql_injection_test", "full_audit"],
      "description": "Qual tipo de verificação de segurança executar."
    },
    "project_path": {
      "type": "string",
      "description": "Caminho do projeto. Se omitido, usa o local_path do projeto ativo.",
      "examples": ["/var/www/html/projetos/portal"]
    },
    "target_url": {
      "type": "string",
      "description": "URL do projeto para 'server_scan' e 'sql_injection_test'. DEVE ser ambiente de staging/development, NUNCA produção para SQLMap.",
      "examples": ["http://portal.test", "http://10.1.1.86:8080"]
    },
    "phpstan_level": {
      "type": "integer",
      "description": "Nível de rigor do PHPStan (0-9). Padrão: 6. Nível 9 é o mais rigoroso.",
      "default": 6,
      "minimum": 0,
      "maximum": 9
    },
    "sqlmap_level": {
      "type": "integer",
      "description": "Nível de agressividade do SQLMap (1-5). Padrão: 1 (mais seguro). NUNCA usar > 2 em staging.",
      "default": 1,
      "minimum": 1,
      "maximum": 3
    },
    "sqlmap_risk": {
      "type": "integer",
      "description": "Nível de risco do SQLMap (1-3). Padrão: 1 (mais seguro). 3 = pode causar alterações no DB.",
      "default": 1,
      "minimum": 1,
      "maximum": 2
    },
    "sqlmap_forms": {
      "type": "boolean",
      "description": "Para 'sql_injection_test': testar automaticamente todos os formulários da URL. Padrão: true.",
      "default": true
    },
    "nikto_output_path": {
      "type": "string",
      "description": "Para 'server_scan': caminho onde salvar o relatório Nikto.",
      "examples": ["/var/www/html/projetos/portal/storage/security/nikto_report.txt"]
    },
    "enlightn_only_security": {
      "type": "boolean",
      "description": "Para 'enlightn': rodar apenas checks de segurança (ignora performance e reliability). Padrão: false.",
      "default": false
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "overall_risk": {
      "type": "string",
      "enum": ["low", "medium", "high", "critical"],
      "description": "Nível de risco geral encontrado. 'low' = seguro para deploy."
    },
    "enlightn_results": {
      "type": "object",
      "description": "Resultados do Enlightn (para 'enlightn' e 'full_audit').",
      "properties": {
        "total_checks": {"type": "integer"},
        "passed": {"type": "integer"},
        "failed": {"type": "integer"},
        "score": {"type": "integer", "description": "Score de 0 a 100"},
        "failures": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "check": {"type": "string", "description": "Nome do check que falhou"},
              "category": {"type": "string", "enum": ["security", "performance", "reliability"]},
              "description": {"type": "string"},
              "docs_url": {"type": "string"}
            }
          }
        }
      }
    },
    "static_analysis_results": {
      "type": "object",
      "description": "Resultados do PHPStan/Larastan (para 'static_analysis' e 'full_audit').",
      "properties": {
        "errors_count": {"type": "integer"},
        "errors": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "file": {"type": "string"},
              "line": {"type": "integer"},
              "message": {"type": "string"},
              "severity": {"type": "string"}
            }
          }
        }
      }
    },
    "dependency_audit_results": {
      "type": "object",
      "description": "Resultados do composer audit + npm audit (para 'dependency_audit' e 'full_audit').",
      "properties": {
        "composer_vulnerabilities": {"type": "integer"},
        "npm_vulnerabilities": {"type": "integer"},
        "critical_cves": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "package": {"type": "string"},
              "installed_version": {"type": "string"},
              "cve": {"type": "string"},
              "severity": {"type": "string"},
              "title": {"type": "string"},
              "fix_version": {"type": "string"}
            }
          }
        }
      }
    },
    "server_scan_results": {
      "type": "object",
      "description": "Resultados do Nikto (para 'server_scan' e 'full_audit').",
      "properties": {
        "findings_count": {"type": "integer"},
        "findings": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "id": {"type": "string"},
              "description": {"type": "string"},
              "severity": {"type": "string"}
            }
          }
        },
        "report_path": {"type": "string"}
      }
    },
    "sql_injection_results": {
      "type": "object",
      "description": "Resultados do SQLMap (para 'sql_injection_test' e 'full_audit').",
      "properties": {
        "vulnerable": {"type": "boolean"},
        "injectable_params": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "url": {"type": "string"},
              "parameter": {"type": "string"},
              "technique": {"type": "string"},
              "dbms": {"type": "string"}
            }
          }
        }
      }
    },
    "vulnerabilities_summary": {
      "type": "array",
      "description": "Resumo consolidado de TODAS as vulnerabilidades encontradas (para 'full_audit').",
      "items": {
        "type": "object",
        "properties": {
          "source": {"type": "string", "description": "De onde veio: enlightn, phpstan, composer_audit, npm_audit, nikto, sqlmap"},
          "severity": {"type": "string", "enum": ["critical", "high", "medium", "low", "informational"]},
          "description": {"type": "string"},
          "remediation": {"type": "string"},
          "file": {"type": "string"},
          "line": {"type": "integer"}
        }
      }
    },
    "error": {"type": "string"}
  }
}
```

### Exemplo Prático: Full Audit

```json
{
  "action": "full_audit",
  "project_path": "/var/www/html/projetos/portal",
  "target_url": "http://portal.test",
  "phpstan_level": 6,
  "sqlmap_level": 1,
  "sqlmap_risk": 1
}
```

Resposta (exemplo com vulnerabilidades detectadas):
```json
{
  "success": true,
  "overall_risk": "high",
  "enlightn_results": {"total_checks": 66, "passed": 61, "failed": 5, "score": 85},
  "dependency_audit_results": {"composer_vulnerabilities": 1, "npm_vulnerabilities": 0,
    "critical_cves": [{"package": "laravel/framework", "cve": "CVE-2026-XXXX", "severity": "high", "fix_version": "12.1.1"}]},
  "sql_injection_results": {"vulnerable": false, "injectable_params": []},
  "vulnerabilities_summary": [
    {"source": "enlightn", "severity": "high", "description": "APP_DEBUG está true em produção", "remediation": "Setar APP_DEBUG=false no .env"},
    {"source": "composer_audit", "severity": "high", "description": "CVE-2026-XXXX em laravel/framework", "remediation": "composer update laravel/framework"}
  ]
}
```

### Restrições de Segurança da Própria Ferramenta

- **SQLMap NUNCA roda em produção** — O SecurityTool verifica o APP_ENV antes de executar. Se for `production`, bloqueia e retorna erro.
- **Nikto é barulhento** — Ele faz milhares de requisições. Rodar apenas em staging/development para não sobrecarregar o servidor.
- **Resultados são confidenciais** — Os relatórios de segurança são salvos em `storage/security/` do projeto, nunca em diretórios públicos.
- **Timeout de 5 minutos** — Se qualquer scan demorar mais de 5 minutos, é abortado para não travar o pipeline.

### Instalação das Ferramentas de Segurança no Servidor

```bash
# Enlightn (no projeto Laravel alvo)
composer require enlightn/enlightn --dev

# Larastan/PHPStan (no projeto Laravel alvo)
composer require larastan/larastan --dev

# Nikto (no servidor — instalação global)
sudo apt install nikto -y

# SQLMap (via Python — já temos Python 3.12 e venv)
source /root/venv/bin/activate
pip install sqlmap
```

---

## 8. DocsTool — Documentação Técnica e Anotações

**Classe:** `App\Tools\DocsTool`
**Responsabilidade:** Criar e atualizar documentação Markdown e gerenciar TODOs/anotações dos agentes. Substitui `MarkdownDocsTool` e `TaskTrackerTool`.

### Ações Disponíveis

| Ação | Descrição | Uso |
|---|---|---|
| `create_doc` | Criar novo documento Markdown | README, CHANGELOG, docs técnicos |
| `update_doc` | Atualizar seção específica de um Markdown existente | Adicionar endpoint na documentação da API |
| `add_todo` | Criar anotação TODO para o agente | Lembrar de dependência pendente enquanto foca em outra coisa |
| `list_todos` | Listar TODOs pendentes | Verificar se há pendências antes de concluir |
| `complete_todo` | Marcar TODO como concluído | Limpeza de anotações |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["create_doc", "update_doc", "add_todo", "list_todos", "complete_todo"]
    },
    "path": {
      "type": "string",
      "description": "Para 'create_doc' e 'update_doc': caminho absoluto do arquivo .md.",
      "examples": ["/var/www/html/projetos/portal/README.md"]
    },
    "content": {
      "type": "string",
      "description": "Para 'create_doc': conteúdo completo em Markdown. Para 'update_doc': conteúdo da seção atualizada."
    },
    "section_heading": {
      "type": "string",
      "description": "Para 'update_doc': título da seção a ser atualizada (ex: '## Endpoints da API')."
    },
    "todo_text": {
      "type": "string",
      "description": "Para 'add_todo': texto da anotação. Ex: 'Falta criar o Factory para User'."
    },
    "todo_id": {
      "type": "string",
      "description": "Para 'complete_todo': ID do TODO a marcar como concluído."
    },
    "subtask_id": {
      "type": "string",
      "description": "UUID da subtask associada. TODOs são isolados por subtask para não misturar anotações de agentes diferentes."
    }
  }
}
```

---

## 9. SocialTool — Publicação em Redes Sociais

**Classe:** `App\Ai\Tools\SocialTool`
**Pacote:** `hamzahassanm/laravel-social-auto-post:^2.2`
**Versão mínima:** PHP 8.2 + Laravel 13
**Responsabilidade:** Publicar conteúdo automaticamente em 8 plataformas de redes sociais. As credenciais de cada plataforma são lidas da tabela `social_accounts` do projeto ativo e injetadas em runtime no config do pacote antes de cada chamada.

---

### Instalação e Configuração

```bash
# 1. Instalar o pacote
composer require hamzahassanm/laravel-social-auto-post:^2.2

# 2. Publicar o arquivo de configuração
php artisan vendor:publish --provider="HamzaHassanM\LaravelSocialAutoPost\SocialShareServiceProvider" --tag=autopost
# Gera: config/autopost.php
```

**Facades registradas automaticamente** (sem `use` adicional necessário):
```php
use SocialMedia;   // Manager unificado — acesso a todas as plataformas
use FaceBook;      // Facade direta do Facebook
use Twitter;       // Facade direta do Twitter/X
use LinkedIn;      // Facade direta do LinkedIn
use Instagram;     // Facade direta do Instagram
use TikTok;        // Facade direta do TikTok
use YouTube;       // Facade direta do YouTube
use Pinterest;     // Facade direta do Pinterest
use Telegram;      // Facade direta do Telegram
```

**Estrutura do `config/autopost.php`** (variáveis de ambiente necessárias):
```php
return [
    'facebook' => [
        'access_token' => env('FACEBOOK_ACCESS_TOKEN'),  // Token de acesso da página
        'page_id'      => env('FACEBOOK_PAGE_ID'),       // ID numérico da Fan Page
    ],
    'twitter' => [
        'bearer_token'        => env('TWITTER_BEARER_TOKEN'),
        'api_key'             => env('TWITTER_API_KEY'),
        'api_secret'          => env('TWITTER_API_SECRET'),
        'access_token'        => env('TWITTER_ACCESS_TOKEN'),
        'access_token_secret' => env('TWITTER_ACCESS_TOKEN_SECRET'),
    ],
    'linkedin' => [
        'access_token'      => env('LINKEDIN_ACCESS_TOKEN'),
        'person_urn'        => env('LINKEDIN_PERSON_URN'),        // urn:li:person:XXXXX
        'organization_urn'  => env('LINKEDIN_ORGANIZATION_URN'),  // urn:li:organization:XXXXX (opcional)
    ],
    'instagram' => [
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        'account_id'   => env('INSTAGRAM_ACCOUNT_ID'),   // ID da conta Business/Creator
    ],
    'tiktok' => [
        'access_token'  => env('TIKTOK_ACCESS_TOKEN'),
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],
    'youtube' => [
        'api_key'      => env('YOUTUBE_API_KEY'),
        'access_token' => env('YOUTUBE_ACCESS_TOKEN'),
        'channel_id'   => env('YOUTUBE_CHANNEL_ID'),
    ],
    'pinterest' => [
        'access_token' => env('PINTEREST_ACCESS_TOKEN'),
        'board_id'     => env('PINTEREST_BOARD_ID'),  // ID do board padrão para pins
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),  // Token do BotFather
        'chat_id'   => env('TELEGRAM_CHAT_ID'),    // ID do canal/grupo/usuário
    ],
];
```

**Como o SocialTool injeta credenciais em runtime** (para suporte multi-projeto):
```php
// Em SocialTool::handle() — antes de qualquer chamada ao pacote
$credentials = SocialAccount::where('project_id', $project->id)
    ->where('platform', $platform)
    ->where('is_active', true)
    ->first();

// Sobrescreve o config em runtime com as credenciais do projeto ativo
config(['autopost.' . $platform => $credentials->credentials]);

// Agora chama o pacote normalmente — ele lerá os valores sobrescritos
```

---

### API do SocialMediaManager (Facade `SocialMedia`)

O `SocialMediaManager` é o ponto de entrada unificado. Permite postar em múltiplas plataformas com uma única chamada.

#### Métodos de Publicação Multi-Plataforma

```php
// Publicar texto + URL em plataformas específicas
SocialMedia::share(array $platforms, string $caption, string $url): array

// Publicar imagem em plataformas específicas
SocialMedia::shareImage(array $platforms, string $caption, string $image_url): array

// Publicar vídeo em plataformas específicas
SocialMedia::shareVideo(array $platforms, string $caption, string $video_url): array

// Publicar em TODAS as 8 plataformas configuradas
SocialMedia::shareToAll(string $caption, string $url): array
SocialMedia::shareImageToAll(string $caption, string $image_url): array
SocialMedia::shareVideoToAll(string $caption, string $video_url): array
```

#### Acessores de Plataforma Individual

```php
// Acesso direto ao service de uma plataforma específica
SocialMedia::facebook()    // → FacebookService
SocialMedia::twitter()     // → TwitterService
SocialMedia::linkedin()    // → LinkedInService
SocialMedia::instagram()   // → InstagramService
SocialMedia::tiktok()      // → TikTokService
SocialMedia::youtube()     // → YouTubeService
SocialMedia::pinterest()   // → PinterestService
SocialMedia::telegram()    // → TelegramService

// Ou via string dinâmica
SocialMedia::platform('facebook')  // → FacebookService
```

#### Utilitários

```php
SocialMedia::getAvailablePlatforms(): array          // ['facebook','twitter','linkedin',...]
SocialMedia::isPlatformAvailable(string $p): bool    // Verifica suporte
SocialMedia::getPlatformService(string $p): ?string  // Retorna nome da classe do service
```

---

### API por Plataforma (Métodos Completos)

#### Facebook (`FaceBook::`)

```php
FaceBook::share(string $caption, string $url): array
FaceBook::shareImage(string $caption, string $image_url): array
FaceBook::shareVideo(string $caption, string $video_url): array
// Nota: vídeos usam upload chunked/resumable para arquivos grandes
FaceBook::getPageInsights(array $metrics = [], array $additionalParams = []): array
// metrics ex: ['page_impressions', 'page_engaged_users', 'page_views_total']
FaceBook::getPageInfo(): array
```

**Credenciais necessárias:** `FACEBOOK_ACCESS_TOKEN` (token de página, não de usuário) + `FACEBOOK_PAGE_ID`

---

#### Instagram (`Instagram::`)

```php
Instagram::shareImage(string $caption, string $image_url): array
// image_url DEVE ser URL pública acessível (não caminho local)
Instagram::shareVideo(string $caption, string $video_url): array
Instagram::shareCarousel(string $caption, array $image_urls): array
// image_urls: array de 2-10 URLs públicas de imagens
Instagram::shareStory(string $caption, string $url): array
Instagram::share(string $caption, string $url): array  // Cria story com texto e URL
Instagram::getAccountInfo(): array
Instagram::getRecentMedia(int $limit = 25): array      // máximo 25
```

**Credenciais necessárias:** `INSTAGRAM_ACCESS_TOKEN` (token de conta Business/Creator Graph API) + `INSTAGRAM_ACCOUNT_ID`

**Restrição importante:** Instagram não aceita caminhos locais — a mídia precisa estar em URL pública. O SocialTool deve garantir que o arquivo foi previamente uploaded para storage público (`/storage/app/public/`) e a URL pública gerada.

---

#### Twitter/X (`Twitter::`)

```php
Twitter::share(string $caption, string $url): array
Twitter::shareImage(string $caption, string $image_url): array
Twitter::shareVideo(string $caption, string $video_url): array
Twitter::getTimeline(int $limit = 10): array  // máximo 100
Twitter::getUserInfo(): array
```

**Credenciais necessárias:** `TWITTER_BEARER_TOKEN` + `TWITTER_API_KEY` + `TWITTER_API_SECRET` + `TWITTER_ACCESS_TOKEN` + `TWITTER_ACCESS_TOKEN_SECRET` (requer app com permissão Read+Write na Twitter Developer Portal)

**Restrição importante:** Twitter tem limite de 280 caracteres por tweet. O SocialTool deve truncar `$caption` se necessário.

---

#### LinkedIn (`LinkedIn::`)

```php
LinkedIn::share(string $caption, string $url): array          // Post no perfil pessoal
LinkedIn::shareImage(string $caption, string $image_url): array
LinkedIn::shareVideo(string $caption, string $video_url): array
LinkedIn::shareToCompanyPage(string $caption, string $url): array  // Post na Company Page
LinkedIn::getUserInfo(): array
```

**Credenciais necessárias:** `LINKEDIN_ACCESS_TOKEN` (OAuth 2.0 com escopo `w_member_social`) + `LINKEDIN_PERSON_URN` (formato `urn:li:person:XXXXX`) + opcional `LINKEDIN_ORGANIZATION_URN` para Company Page

---

#### TikTok (`TikTok::`)

```php
TikTok::shareVideo(string $caption, string $video_url): array
// Upload em 3 etapas: initialize → upload → publish (gerenciado internamente)
TikTok::share(string $caption, string $url): array
// Cria vídeo com text overlay a partir da URL
TikTok::shareImage(string $caption, string $image_url): array
// Converte imagem para formato de vídeo e publica
TikTok::getUserInfo(): array
TikTok::getUserVideos(int $max_count = 20): array  // máximo 20
```

**Credenciais necessárias:** `TIKTOK_ACCESS_TOKEN` + `TIKTOK_CLIENT_KEY` + `TIKTOK_CLIENT_SECRET` (requer TikTok for Developers com permissão `video.upload`)

---

#### YouTube (`YouTube::`)

```php
YouTube::shareVideo(string $caption, string $video_url): array
YouTube::share(string $caption, string $url): array
YouTube::shareImage(string $caption, string $image_url): array
YouTube::createCommunityPost(string $text, string $url, string $type = 'text'): array
// type: 'text' | 'image' | 'video'
YouTube::getChannelInfo(): array
YouTube::getChannelVideos(int $maxResults = 25): array
YouTube::getVideoAnalytics(string $videoId): array
```

**Credenciais necessárias:** `YOUTUBE_API_KEY` + `YOUTUBE_ACCESS_TOKEN` (OAuth 2.0 com escopo `youtube.upload`) + `YOUTUBE_CHANNEL_ID`

---

#### Pinterest (`Pinterest::`)

```php
Pinterest::shareImage(string $caption, string $image_url): array   // Pin de imagem no board padrão
Pinterest::shareVideo(string $caption, string $video_url): array   // Pin de vídeo
Pinterest::share(string $caption, string $url): array
Pinterest::createPin(string $note, string $mediaUrl, string $mediaType = 'image'): array
// mediaType: 'image' | 'video'
Pinterest::createBoard(string $name, string $description = '', string $privacy = 'PUBLIC'): array
// privacy: 'PUBLIC' | 'PROTECTED' | 'SECRET'
Pinterest::getBoards(int $pageSize = 25): array
Pinterest::getBoardPins(string $boardId, int $pageSize = 25): array
Pinterest::getUserInfo(): array
Pinterest::getPinAnalytics(string $pinId): array
Pinterest::searchPins(string $query, int $pageSize = 25): array
```

**Credenciais necessárias:** `PINTEREST_ACCESS_TOKEN` (OAuth 2.0 com escopo `boards:read`, `boards:write`, `pins:read`, `pins:write`) + `PINTEREST_BOARD_ID`

---

#### Telegram (`Telegram::`)

```php
Telegram::share(string $caption, string $url): array
Telegram::shareImage(string $caption, string $image_url): array
Telegram::shareVideo(string $caption, string $video_url): array
Telegram::shareDocument(string $caption, string $document_url): array  // PDFs, ZIPs, etc.
Telegram::getUpdates(): array  // Busca mensagens recebidas pelo bot
```

**Credenciais necessárias:** `TELEGRAM_BOT_TOKEN` (obtido via @BotFather) + `TELEGRAM_CHAT_ID` (ID do canal, grupo ou usuário — prefixar canais com `-100`)

---

### Tratamento de Erros

O pacote retorna arrays com chave `error_count` e lança `SocialMediaException` em falhas críticas:

```php
use HamzaHassanM\LaravelSocialAutoPost\Exceptions\SocialMediaException;

try {
    $result = SocialMedia::share(['facebook', 'twitter', 'linkedin'], $caption, $url);

    // Resultado multi-plataforma:
    // $result['success_count'] → int
    // $result['error_count']   → int
    // $result['results']       → array por plataforma
    // $result['errors']        → array com erros por plataforma

    if ($result['error_count'] > 0) {
        foreach ($result['errors'] as $platform => $error) {
            Log::warning("SocialTool falhou em {$platform}: {$error}");
        }
    }

} catch (SocialMediaException $e) {
    // Erro crítico (credenciais inválidas, timeout, etc.)
    Log::error("SocialTool erro crítico: " . $e->getMessage());
}
```

**Recursos internos do pacote:**
- Retry automático com exponential backoff em falhas de rede
- Validação de inputs antes de chamar a API
- Logging integrado de todas as chamadas
- Rate limiting respeitado por plataforma

---

### Tabela `social_accounts` (Credenciais por Projeto)

As credenciais são armazenadas criptografadas por projeto para suporte multi-tenant:

```php
// Migration: 2026_04_11_112650_create_social_accounts_table.php
Schema::create('social_accounts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
    $table->enum('platform', ['facebook','instagram','twitter','linkedin','tiktok','youtube','pinterest','telegram']);
    $table->json('credentials');      // criptografado via Model $casts
    $table->boolean('is_active')->default(true);
    $table->timestamp('token_expires_at')->nullable();
    $table->timestamps();
    $table->unique(['project_id', 'platform']);
});
```

```php
// Model: App\Models\SocialAccount
protected $casts = [
    'credentials'      => 'encrypted:array',  // criptografia nativa Laravel
    'token_expires_at' => 'datetime',
];
```

**Estrutura de `credentials` por plataforma:**
```json
// facebook
{"access_token": "EAAx...", "page_id": "123456789"}

// twitter
{"bearer_token": "AAA...", "api_key": "xxx", "api_secret": "xxx", "access_token": "xxx", "access_token_secret": "xxx"}

// linkedin
{"access_token": "AQV...", "person_urn": "urn:li:person:XXXXX", "organization_urn": "urn:li:organization:XXXXX"}

// instagram
{"access_token": "EAAx...", "account_id": "17841400000000000"}

// tiktok
{"access_token": "act.xxx", "client_key": "xxx", "client_secret": "xxx"}

// youtube
{"api_key": "AIza...", "access_token": "ya29...", "channel_id": "UCxxx"}

// pinterest
{"access_token": "pina_xxx", "board_id": "12345678901234"}

// telegram
{"bot_token": "123456:ABCxxx", "chat_id": "-1001234567890"}
```

---

### JSON Schema de Entrada da SocialTool

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["share", "share_image", "share_video", "share_to_all", "share_carousel", "share_document", "create_community_post", "create_pin", "create_board", "get_analytics", "get_account_info", "get_recent_media"],
      "description": "Ação a executar."
    },
    "platforms": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["facebook", "instagram", "twitter", "linkedin", "tiktok", "youtube", "pinterest", "telegram"]
      },
      "description": "Plataformas alvo. Omitir para publicar em todas as configuradas no projeto (share_to_all)."
    },
    "caption": {
      "type": "string",
      "description": "Texto do post/legenda. Suporta emojis e hashtags. Twitter: máximo 280 chars.",
      "examples": [
        "Nova feature lançada! #Laravel13 #AIdev",
        "Deploy v2.1.0 concluído com sucesso."
      ]
    },
    "url": {
      "type": "string",
      "description": "URL pública a incluir no post. Obrigatório para share/share_to_all.",
      "examples": ["https://meusite.com/blog/nova-feature"]
    },
    "media_url": {
      "type": "string",
      "description": "URL pública da mídia (imagem ou vídeo). DEVE ser acessível publicamente — não use caminhos locais. Para Instagram/TikTok/YouTube, obrigatório.",
      "examples": [
        "https://meusite.com/storage/screenshots/deploy.png",
        "https://meusite.com/storage/videos/demo.mp4"
      ]
    },
    "image_urls": {
      "type": "array",
      "items": {"type": "string"},
      "minItems": 2,
      "maxItems": 10,
      "description": "Para share_carousel (Instagram): array de 2-10 URLs públicas de imagens."
    },
    "platform_id": {
      "type": "string",
      "description": "Para get_analytics: ID do post/pin/vídeo retornado pelo post anterior."
    },
    "board_id": {
      "type": "string",
      "description": "Para create_pin ou get_board_pins no Pinterest."
    },
    "board_name": {
      "type": "string",
      "description": "Para create_board no Pinterest."
    },
    "privacy": {
      "type": "string",
      "enum": ["PUBLIC", "PROTECTED", "SECRET"],
      "description": "Para create_board no Pinterest. Default: PUBLIC."
    },
    "community_post_type": {
      "type": "string",
      "enum": ["text", "image", "video"],
      "description": "Para create_community_post no YouTube. Default: text."
    }
  }
}
```

### Exemplos de Chamada pelo Agente

```json
// Anunciar lançamento de feature no LinkedIn e Twitter
{
  "action": "share",
  "platforms": ["linkedin", "twitter"],
  "caption": "Nova feature: autenticação social implementada! #Laravel13 #AIdev",
  "url": "https://github.com/usuario/projeto/releases/v2.1.0"
}

// Publicar screenshot de deploy em todas as redes
{
  "action": "share_image",
  "platforms": ["facebook", "instagram", "linkedin"],
  "caption": "Deploy v2.1.0 concluído! Sistema 100% operacional.",
  "media_url": "https://meusite.com/storage/screenshots/deploy-success.png"
}

// Publicar em TODAS as 8 plataformas configuradas
{
  "action": "share_to_all",
  "caption": "AI-Dev está em produção! Desenvolvemos um sistema completo em 4 horas.",
  "url": "https://andradeitalo.ai"
}

// Carrossel de screenshots no Instagram
{
  "action": "share_carousel",
  "platforms": ["instagram"],
  "caption": "Antes e depois do refactor. Confira os resultados!",
  "image_urls": [
    "https://meusite.com/storage/before.png",
    "https://meusite.com/storage/after.png",
    "https://meusite.com/storage/metrics.png"
  ]
}

// Upload de demo no YouTube e TikTok
{
  "action": "share_video",
  "platforms": ["youtube", "tiktok"],
  "caption": "AI-Dev gerando um Resource Filament completo em 30 segundos",
  "media_url": "https://meusite.com/storage/videos/demo-resource.mp4"
}

// Enviar PDF de relatório no Telegram
{
  "action": "share_document",
  "platforms": ["telegram"],
  "caption": "Relatório de auditoria de segurança — Sprint 12",
  "media_url": "https://meusite.com/storage/reports/security-audit-sprint12.pdf"
}

// Criar pin no Pinterest
{
  "action": "create_pin",
  "platforms": ["pinterest"],
  "caption": "Dashboard Filament v5 com dark mode e animações Anime.js",
  "media_url": "https://meusite.com/storage/portfolio/dashboard.png",
  "url": "https://andradeitalo.ai/portfolio"
}

// Buscar métricas de analytics do Facebook
{
  "action": "get_analytics",
  "platforms": ["facebook"],
  "platform_id": "123456789_987654321"
}

// Post na Company Page do LinkedIn
// Use SocialMedia::linkedin()->shareToCompanyPage() diretamente no SocialTool
{
  "action": "share",
  "platforms": ["linkedin"],
  "caption": "Nossa empresa acabou de lançar o AI-Dev, sistema de desenvolvimento autônomo.",
  "url": "https://andradeitalo.ai",
  "target": "company_page"
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean", "description": "true se pelo menos uma plataforma teve sucesso"},
    "success_count": {"type": "integer", "description": "Número de plataformas onde publicou com sucesso"},
    "error_count": {"type": "integer", "description": "Número de plataformas onde falhou"},
    "results": {
      "type": "object",
      "description": "Resultado por plataforma",
      "additionalProperties": {
        "type": "object",
        "properties": {
          "success": {"type": "boolean"},
          "post_id": {"type": "string", "description": "ID do post criado (usar em get_analytics)"},
          "url": {"type": "string", "description": "URL pública do post criado (quando disponível)"},
          "error": {"type": "string", "description": "Mensagem de erro se falhou"}
        }
      }
    }
  }
}
```

**Exemplo de saída real:**
```json
{
  "success": true,
  "success_count": 2,
  "error_count": 1,
  "results": {
    "facebook": {"success": true, "post_id": "123456789_987654321"},
    "linkedin": {"success": true, "post_id": "urn:li:share:7123456789"},
    "twitter": {"success": false, "error": "Rate limit exceeded. Try again in 15 minutes."}
  }
}
```

### Boas Práticas para o Agente

1. **Mídia sempre em URL pública** — Nunca passe caminhos locais (`/var/www/...`). Use `Storage::url()` para gerar a URL pública antes de chamar o SocialTool.
2. **Twitter: limite de 280 chars** — Truncar `caption` se necessário antes de chamar.
3. **Instagram: conta Business/Creator** — Contas pessoais não têm acesso à Graph API. Se `shareImage()` falhar com 403, o projeto não tem conta Business configurada.
4. **TikTok: só vídeos nativos** — `shareVideo()` é o método principal. `shareImage()` converte a imagem para vídeo internamente.
5. **Tokens expirados** — Verificar `social_accounts.token_expires_at` antes de publicar. Se expirado, notificar humano via MetaTool em vez de tentar publicar e falhar.
6. **Falha parcial é normal** — A publicação multi-plataforma pode ter `error_count > 0` e ainda ser considerada sucesso parcial. Logar as falhas mas não abortar a task.
7. **`share_to_all` vs `share`** — Usar `share_to_all` apenas quando o projeto tiver TODAS as 8 plataformas configuradas. Verificar `social_accounts` antes para evitar erros de credenciais faltando.

---

## 10. MetaTool — Auto-Evolução e Logging de Impossibilidades

**Classe:** `App\Ai\Tools\MetaTool`
**Responsabilidade:** Permitir que o sistema evolua criando novas ferramentas permanentes para usos recorrentes não mapeados, e registrar situações onde o agente não conseguiu resolver o problema (para análise humana posterior).

### Ações Disponíveis

| Ação | Descrição | Uso |
|---|---|---|
| `create_tool` | Propor criação de nova ferramenta | Quando o agente precisa de uma capacidade que não existe |
| `log_impossibility` | Registrar que a tarefa é impossível com as ferramentas atuais | Escalar honestamente para humano |
| `request_human` | Solicitar intervenção humana explicitamente | Quando precisa de decisão de design que não pode assumir |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["create_tool", "log_impossibility", "request_human"]
    },
    "tool_name": {
      "type": "string",
      "description": "Para 'create_tool': nome proposto para a nova ferramenta."
    },
    "tool_description": {
      "type": "string",
      "description": "Para 'create_tool': o que a ferramenta faria e por que é necessária."
    },
    "tool_actions": {
      "type": "array",
      "description": "Para 'create_tool': lista de ações que a nova ferramenta teria.",
      "items": {"type": "string"}
    },
    "reason": {
      "type": "string",
      "description": "Para 'log_impossibility' e 'request_human': explicação detalhada de por que não pode continuar.",
      "examples": [
        "A tarefa requer acesso SSH a um servidor externo (192.168.1.50) que não está nas ferramentas disponíveis",
        "O PRD pede integração com API do Stripe mas não temos as credenciais nem a documentação do webhook format"
      ]
    },
    "subtask_id": {
      "type": "string",
      "description": "UUID da subtask relacionada."
    },
    "suggested_action": {
      "type": "string",
      "description": "Para 'request_human': o que o humano deveria fazer para desbloquear o agente.",
      "examples": [
        "Fornecer as credenciais do Stripe no .env (STRIPE_KEY e STRIPE_SECRET)",
        "Decidir se o avatar do usuário deve ser armazenado no S3 ou no disco local"
      ]
    }
  }
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "logged": {"type": "boolean", "description": "Se a impossibilidade foi registrada com sucesso"},
    "tool_proposal_id": {"type": "string", "description": "ID da proposta de nova ferramenta (para revisão humana)"},
    "notification_sent": {"type": "boolean", "description": "Se a notificação foi enviada ao humano via Filament"},
    "error": {"type": "string"}
  }
}
```

---

## Resumo Visual: Mapa de Ferramentas → Casos de Uso

```text
┌──────────────────────────────────────────────────────────────────────┐
│              LARAVEL AI SDK (tool calling nativo)                     │
│                                                                       │
│  LLM response → Parse tool_calls → schema() validate → handle()     │
│                                                                       │
│  ┌───────────┐  ┌───────────┐  ┌──────────────┐  ┌───────────┐      │
│  │ ShellTool │  │ FileTool  │  │ DatabaseTool │  │ GitTool   │      │
│  │           │  │           │  │              │  │           │      │
│  │ artisan   │  │ read      │  │ describe     │  │ status    │      │
│  │ npm       │  │ write     │  │ query        │  │ commit    │      │
│  │ composer  │  │ patch     │  │ execute      │  │ push      │      │
│  │ terminal  │  │ insert    │  │ dump         │  │ branch    │      │
│  │           │  │ delete    │  │ optimize     │  │ merge     │      │
│  │           │  │ rename    │  │              │  │ github    │      │
│  │           │  │ list_dir  │  │              │  │           │      │
│  │           │  │ tree      │  │              │  │           │      │
│  │           │  │ perms     │  │              │  │           │      │
│  └───────────┘  └───────────┘  └──────────────┘  └───────────┘      │
│                                                                       │
│  ┌─────────────┐  ┌───────────┐  ┌───────────────┐                   │
│  │ SearchTool  │  │ TestTool  │  │ SecurityTool  │                   │
│  │             │  │           │  │               │                   │
│  │ ddg search  │  │ run tests │  │ enlightn      │                   │
│  │ firecrawl   │  │ dusk      │  │ phpstan       │                   │
│  │ grep_code   │  │ screenshot│  │ dep_audit     │                   │
│  │ find_files  │  │ coverage  │  │ nikto scan    │                   │
│  │             │  │           │  │ sqlmap test   │                   │
│  │             │  │           │  │ full_audit    │                   │
│  └─────────────┘  └───────────┘  └───────────────┘                   │
│                                                                       │
│  ┌──────────┐  ┌──────────────────────────────────────┐  ┌────────┐ │
│  │ DocsTool │  │ SocialTool                           │  │ Meta   │ │
│  │          │  │                                      │  │ Tool   │ │
│  │ create   │  │ facebook instagram twitter linkedin  │  │ create │ │
│  │ update   │  │ tiktok youtube pinterest telegram    │  │ log    │ │
│  │ todos    │  │ share / share_to_all / upload_video  │  │        │ │
│  └──────────┘  └──────────────────────────────────────┘  └────────┘ │
│                                                                       │
│  10 Ferramentas Atômicas | Todo input validado contra JSON Schema    │
│  Auditoria: Todo call logado em tool_calls_log                       │
│  Sandbox: Apenas /var/www/html/projetos/ acessível                   │
└──────────────────────────────────────────────────────────────────────┘
```

---

**Nota de Segurança:** Todas as ferramentas operam sob:
1. **Validação de JSON Schema** — Parâmetros inválidos são rejeitados antes da execução.
2. **Sandbox de diretórios** — Apenas caminhos dentro de `/var/www/html/projetos/` são permitidos.
3. **Logs de auditoria** — Toda execução é gravada em `tool_calls_log` com timestamp, agente e resultado.
4. **Timeouts** — Todo comando tem timeout máximo para evitar processos órfãos.
5. **Backup automático** — Operações destrutivas (delete file, truncate table) criam backup antes de executar.
