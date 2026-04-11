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
**Responsabilidade:** Publicar conteúdo automaticamente em 8 plataformas de redes sociais. As credenciais de cada plataforma são lidas da tabela `social_accounts` do projeto ativo, injetadas em runtime.

**Instalação do pacote:**
```bash
composer require hamzahassanm/laravel-social-auto-post:^2.2
php artisan vendor:publish --provider="HamzaHassanM\LaravelSocialAutoPost\SocialAutoPostServiceProvider"
```

### Ações Disponíveis

| Ação | Descrição | Exemplo de Uso |
|---|---|---|
| `share` | Publicar em plataformas específicas | Post de lançamento no LinkedIn + Twitter |
| `share_to_all` | Publicar em todas as plataformas configuradas | Anúncio de deploy em todas as redes |
| `upload_video` | Fazer upload e publicar vídeo | Demo de feature no YouTube + TikTok |
| `share_carousel` | Publicar carrossel de imagens | Portfolio de screenshots no Instagram |
| `get_analytics` | Buscar métricas da última publicação | Verificar engajamento pós-post |

### Plataformas Suportadas

| Plataforma | Enum | Tipos de Conteúdo |
|---|---|---|
| Facebook | `facebook` | Texto, imagens, vídeos, stories, páginas |
| Instagram | `instagram` | Fotos, Reels, Stories, Carrossel |
| Twitter/X | `twitter` | Tweets, imagens, vídeos |
| LinkedIn | `linkedin` | Posts, artigos, Company pages |
| TikTok | `tiktok` | Vídeos curtos |
| YouTube | `youtube` | Upload de vídeos, playlists |
| Pinterest | `pinterest` | Pins com imagens, boards |
| Telegram | `telegram` | Mensagens, arquivos, fotos, canais |

### JSON Schema de Entrada

```json
{
  "type": "object",
  "required": ["action", "message"],
  "properties": {
    "action": {
      "type": "string",
      "enum": ["share", "share_to_all", "upload_video", "share_carousel", "get_analytics"],
      "description": "Qual ação executar."
    },
    "platforms": {
      "type": "array",
      "items": {"type": "string", "enum": ["facebook", "instagram", "twitter", "linkedin", "tiktok", "youtube", "pinterest", "telegram"]},
      "description": "Plataformas alvo. Se omitido, publica em todas as configuradas no projeto."
    },
    "message": {
      "type": "string",
      "description": "Texto do post/legenda. Suporta emojis e hashtags.",
      "examples": ["🚀 Nova feature lançada! #Laravel #AIdev", "Deploy realizado com sucesso em produção."]
    },
    "url": {
      "type": "string",
      "description": "URL a ser incluída no post (opcional).",
      "examples": ["https://meusite.com/blog/nova-feature"]
    },
    "media_path": {
      "type": "string",
      "description": "Caminho absoluto para imagem ou vídeo a ser publicado.",
      "examples": ["/var/www/html/projetos/portal/storage/app/screenshots/deploy.png"]
    }
  }
}
```

### Exemplos de Chamada pelo Agente

```json
// Publicar lançamento de feature no LinkedIn e Twitter
{
  "action": "share",
  "platforms": ["linkedin", "twitter"],
  "message": "🚀 Nova feature: autenticação social implementada! #Laravel13 #AIdev",
  "url": "https://github.com/usuario/projeto/releases/v2.1.0"
}

// Publicar deploy em todas as redes configuradas
{
  "action": "share_to_all",
  "message": "✅ Deploy v2.1.0 concluído com sucesso! Sistema 100% operacional."
}

// Upload de demo no YouTube
{
  "action": "upload_video",
  "platforms": ["youtube"],
  "message": "Demo: AI-Dev gerando um Resource Filament completo em 30 segundos",
  "media_path": "/var/www/html/projetos/ai-dev/storage/videos/demo-resource.mp4"
}
```

### JSON Schema de Saída

```json
{
  "type": "object",
  "properties": {
    "success": {"type": "boolean"},
    "published": {
      "type": "array",
      "items": {"type": "string"},
      "description": "Plataformas onde a publicação foi bem-sucedida"
    },
    "failed": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "platform": {"type": "string"},
          "error": {"type": "string"}
        }
      },
      "description": "Plataformas onde a publicação falhou, com motivo"
    },
    "post_ids": {
      "type": "object",
      "description": "IDs dos posts criados por plataforma (para analytics futuros)"
    }
  }
}
```

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
