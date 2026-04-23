# Schema do PRD (Product Requirement Document)

Este documento define o formato **exato** que todo PRD e Sub-PRD deve seguir no ecossistema AI-Dev. O Sistema Inteiro a ser desenvolvido, cada módulo e cada submódulo devem ter um PRD salvo no banco de dados (`projects.prd_payload` e `project_modules.prd_payload`), assim como cada task também deve ter um PRD salvo no banco de dados (`tasks.prd_payload`). Dessa forma, as IAs ou os humanos que irão trabalhar nas atividades sabem exatamente o que devem fazer. Quando o Orchestrator quebra um PRD em Sub-PRDs para as subtasks, o campo `subtasks.sub_prd_payload` DEVE seguir o schema de Sub-PRD.

O `PRDValidator.php` (em `app/Services/`) valida todo PRD contra estes schemas ANTES de aceitar a task. Se o JSON for inválido, a task é rejeitada com uma mensagem clara do que falta.

> **Todos os caminhos, tabelas e colunas citados em um PRD referem-se ao Projeto Alvo** — nunca ao ai-dev-core. Exemplos: `context.related_files` aponta para arquivos dentro de `projects.local_path`; `context.related_tables` são tabelas no banco daquele projeto (consultadas pelo `BoostTool` via `database-schema` do Projeto Alvo, não pelo schema do ai-dev-core); `acceptance_criteria` descrevem comportamento a validar no código e banco do alvo. O PRD em si é **armazenado** no banco do ai-dev-core (em `tasks.prd_payload`) — mas **descreve trabalho a ser feito no alvo**. Para a separação canônica entre ai-dev-core e Projeto Alvo, consulte `README.md → Arquitetura em Duas Camadas`.

---

## 0. JSON Schema do PRD do Projeto (Project PRD)

Este é o formato que preenche o campo `projects.prd_payload`. Gerado pelo `ProjectPrdAgent`. **Contém apenas módulos de alto nível** — submódulos são proibidos neste nível.

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AI-Dev Project PRD",
  "type": "object",
  "required": ["title", "objective", "modules"],
  "properties": {
    "title": {
      "type": "string",
      "description": "Título do projeto. Ex: 'Sistema de Gestão Jurídica'"
    },
    "objective": {
      "type": "string",
      "description": "Descrição clara do objetivo do sistema."
    },
    "modules": {
      "type": "array",
      "description": "Lista de módulos de ALTO NÍVEL. NÃO incluir submódulos aqui.",
      "items": {
        "type": "object",
        "required": ["name", "description", "priority"],
        "properties": {
          "name": {
            "type": "string",
            "description": "Nome do módulo. Ex: 'Gestão de Clientes'"
          },
          "description": {
            "type": "string",
            "description": "O que este módulo abrange em 1-2 frases."
          },
          "priority": {
            "type": "string",
            "enum": ["high", "normal", "low"],
            "description": "Prioridade de implementação"
          },
          "dependencies": {
            "type": "array",
            "items": { "type": "string" },
            "description": "Nomes de outros módulos que devem ser concluídos antes deste",
            "default": []
          }
        }
      },
      "minItems": 1
    }
  }
}
```

---

## 1. JSON Schema do PRD do Módulo (Module PRD)

Este é o formato que preenche o campo `project_modules.prd_payload`. Gerado pelo `ModulePrdAgent`.

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AI-Dev Module PRD",
  "type": "object",
  "required": ["title", "objective", "scope", "needs_submodules"],
  "properties": {
    "title": {
      "type": "string",
      "description": "Título técnico do módulo"
    },
    "objective": {
      "type": "string",
      "description": "Objetivo claro e técnico deste módulo"
    },
    "scope": {
      "type": "string",
      "description": "Escopo do que está incluído e EXCLUÍDO deste módulo"
    },
    "database_schema": {
      "type": "object",
      "properties": {
        "tables": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "name": { "type": "string" },
              "columns": {
                "type": "array",
                "items": {
                  "type": "object",
                  "properties": {
                    "name": { "type": "string" },
                    "type": { "type": "string" },
                    "nullable": { "type": "boolean" },
                    "default": {},
                    "relations": { "type": "string" }
                  }
                }
              }
            }
          }
        }
      }
    },
    "api_endpoints": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "method": { "type": "string", "enum": ["GET", "POST", "PUT", "PATCH", "DELETE"] },
          "path": { "type": "string" },
          "description": { "type": "string" }
        }
      }
    },
    "business_rules": {
      "type": "object",
      "description": "Regras de negócio como pares chave-valor"
    },
    "components": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "name": { "type": "string" },
          "type": { "type": "string" },
          "description": { "type": "string" }
        }
      }
    },
    "workflows": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "name": { "type": "string" },
          "steps": { "type": "array", "items": { "type": "string" } }
        }
      }
    },
    "acceptance_criteria": {
      "type": "object",
      "description": "Critérios de aceitação como pares chave-valor"
    },
    "estimated_complexity": {
      "type": "string",
      "enum": ["low", "medium", "high", "very_high"]
    },
    "estimated_hours": {
      "type": "number",
      "description": "Horas estimadas de desenvolvimento"
    },
    "needs_submodules": {
      "type": "boolean",
      "description": "TRUE se este módulo precisa de submódulos; FALSE se é uma folha (recebe tasks diretamente)"
    },
    "submodules": {
      "type": "array",
      "description": "Lista de submódulos (apenas quando needs_submodules = true)",
      "items": {
        "type": "object",
        "required": ["name", "description", "priority"],
        "properties": {
          "name": { "type": "string" },
          "description": { "type": "string" },
          "priority": { "type": "string", "enum": ["high", "normal", "low"] }
        }
      }
    }
  }
}
```

---

## 2. JSON Schema do PRD Principal (Task)

Este é o formato que preenche o campo `tasks.prd_payload`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AI-Dev PRD (Product Requirement Document)",
  "type": "object",
  "required": ["title", "objective", "acceptance_criteria", "knowledge_areas"],
  "properties": {
    "title": {
      "type": "string",
      "description": "Título curto e descritivo da tarefa. Ex: 'Criar CRUD completo de Usuários no Filament v5'",
      "minLength": 10,
      "maxLength": 500
    },
    "objective": {
      "type": "string",
      "description": "Descrição detalhada do que precisa ser feito. Quanto mais específico, melhor o resultado dos agentes. Evite ambiguidade — diga exatamente o que quer.",
      "minLength": 50
    },
    "acceptance_criteria": {
      "type": "array",
      "description": "Lista de critérios objetivos e mensuráveis que definem quando a tarefa está 'pronta'. O QA Auditor usa esta lista como checklist — cada item precisa ter SIM ou NÃO claro.",
      "items": {
        "type": "string"
      },
      "minItems": 1,
      "examples": [
        "Resource com listagem paginada mostrando nome, email e data de cadastro",
        "Formulário com validação de email único usando Rule::unique()",
        "Soft delete habilitado com SoftDeletes trait no Model",
        "Botão de 'Restaurar' visível nos registros deletados",
        "Testes Pest cobrindo criação, edição, listagem e exclusão"
      ]
    },
    "constraints": {
      "type": "array",
      "description": "Restrições técnicas obrigatórias. O que NÃO fazer ou o que DEVE ser usado. Os agentes tratam constraints como regras invioláveis.",
      "items": {
        "type": "string"
      },
      "default": [],
      "examples": [
        "Utilizar exclusivamente FormBuilder do Filament v5 — proibido criar formulários Blade manuais",
        "Não criar rotas manuais — usar apenas o auto-routing do Filament Resource",
        "Todas as queries devem usar Eloquent — proibido DB::raw() exceto para otimizações justificadas",
        "Não instalar pacotes npm/composer extras sem aprovação prévia"
      ]
    },
    "knowledge_areas": {
      "type": "array",
      "description": "Áreas de conhecimento necessárias para esta tarefa. Determina quais agentes serão alocados e quais padrões de código serão carregados no prompt.",
      "items": {
        "type": "string",
        "enum": ["backend", "frontend", "database", "filament", "devops", "testing", "design"]
      },
      "minItems": 1
    },
    "context": {
      "type": "object",
      "description": "Contexto adicional que ajuda os agentes a entender o cenário. Opcional, mas extremamente útil para tasks complexas.",
      "properties": {
        "related_files": {
          "type": "array",
          "description": "Caminhos absolutos de arquivos que o agente DEVE ler antes de começar. Ex: um Model que já existe e precisa ser estendido.",
          "items": { "type": "string" },
          "examples": ["/var/www/html/projetos/portal/app/Models/User.php"]
        },
        "related_tables": {
          "type": "array",
          "description": "Tabelas do banco de dados relevantes. O agente fará DESCRIBE nestas tabelas para entender a estrutura.",
          "items": { "type": "string" },
          "examples": ["users", "roles", "permissions"]
        },
        "reference_urls": {
          "type": "array",
          "description": "URLs de documentação ou exemplos que o agente deve consultar via `DocSearchTool` (Laravel Boost `search-docs`).",
          "items": { "type": "string" },
          "examples": ["https://filamentphp.com/docs/panels/resources"]
        },
        "screenshots": {
          "type": "array",
          "description": "Caminhos para screenshots do problema ou mockup do design desejado (para tasks visuais).",
          "items": { "type": "string" }
        },
        "error_stack_trace": {
          "type": "string",
          "description": "Stack trace completo se esta task foi gerada pelo Sentinela. Inclui arquivo, linha e mensagem de erro."
        },
        "previous_task_id": {
          "type": "string",
          "description": "UUID de uma task anterior relacionada (ex: refatoração de algo que já foi implementado)."
        }
      }
    },
    "priority_hint": {
      "type": "string",
      "description": "Dica de prioridade para o Orchestrator. O campo priority numérico na tabela tasks é calculado com base nisso.",
      "enum": ["critical", "high", "medium", "low"],
      "default": "medium"
    },
    "estimated_complexity": {
      "type": "string",
      "description": "Estimativa de complexidade. Ajuda o Orchestrator a decidir quantos Sub-PRDs criar.",
      "enum": ["trivial", "simple", "moderate", "complex", "very_complex"],
      "default": "moderate"
    }
  }
}
```

---

## 3. Exemplo Completo de PRD Principal

Abaixo está um exemplo real e completo de um PRD bem escrito para criar um CRUD de usuários em Filament v5:

```json
{
  "title": "Criar Resource completo de Gestão de Usuários no Filament v5",
  "objective": "Implementar um Resource no Filament v5 para gestão completa de usuários do sistema. O Resource deve incluir listagem com busca e filtros, formulário de criação e edição com validação, soft delete com possibilidade de restauração, e exportação em CSV. O Model User já existe mas precisa ser estendido com novos campos (avatar, phone, role). A migration de alteração deve ser criada separadamente. Os testes devem cobrir todos os cenários CRUD usando Pest.",
  "acceptance_criteria": [
    "Migration 'alter_users_add_profile_fields' criada com campos: avatar (string nullable), phone (string nullable), role (enum: admin/editor/viewer default viewer)",
    "Model User atualizado com: fillable, casts para role (enum), accessor para avatar URL, scope scopeByRole()",
    "UserResource com FormSchema: TextInput para name (required), email (required, unique exceto current), phone (tel mask), Select para role, FileUpload para avatar",
    "UserResource com TableColumns: avatar (ImageColumn), name (searchable, sortable), email (searchable), role (BadgeColumn com cores), created_at (sortable)",
    "Filtros na listagem: SelectFilter por role, TrashedFilter para soft deletes",
    "BulkAction para deletar múltiplos usuários selecionados",
    "Ação 'Restaurar' visível apenas em registros soft-deleted",
    "HeaderAction para 'Exportar CSV' usando ExportAction do Filament",
    "Testes Pest: criar usuário, editar usuário, deletar usuário, restaurar usuário, listar usuários filtrados por role, validação de email único",
    "Nenhum erro no php artisan test após implementação"
  ],
  "constraints": [
    "Usar exclusivamente FormBuilder e TableBuilder do Filament v5 — proibido Blade manual",
    "Não criar rotas manuais — usar auto-routing do Resource",
    "Usar Enum PHP nativa para roles (App\\Enums\\UserRole) — não string mágica",
    "Avatar deve ser salvo em storage/app/public/avatars com disk 'public'",
    "Migration deve ser idempotente — usar Schema::hasColumn() para evitar erro se rodar duas vezes"
  ],
  "knowledge_areas": ["backend", "filament", "database", "testing"],
  "context": {
    "related_files": [
      "/var/www/html/projetos/portal/app/Models/User.php",
      "/var/www/html/projetos/portal/database/migrations/0001_01_01_000000_create_users_table.php"
    ],
    "related_tables": ["users"],
    "reference_urls": [
      "https://filamentphp.com/docs/panels/resources/creating-records",
      "https://filamentphp.com/docs/tables/columns"
    ]
  },
  "priority_hint": "high",
  "estimated_complexity": "moderate"
}
```

---

## 4. JSON Schema do Sub-PRD (Subtask)

Quando o Orchestrator quebra o PRD principal, cada subtask recebe um Sub-PRD menor e focado. Este é o formato do campo `subtasks.sub_prd_payload`:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AI-Dev Sub-PRD (Subtask Requirement)",
  "type": "object",
  "required": ["title", "objective", "acceptance_criteria", "assigned_agent", "files_to_create_or_modify"],
  "properties": {
    "title": {
      "type": "string",
      "description": "Título focado na responsabilidade específica deste subagente.",
      "minLength": 10,
      "maxLength": 300
    },
    "objective": {
      "type": "string",
      "description": "Descrição detalhada do que este subagente específico deve fazer. NÃO inclui responsabilidades de outros subagentes.",
      "minLength": 30
    },
    "acceptance_criteria": {
      "type": "array",
      "description": "Critérios de aceite APENAS para esta subtask. O QA audita cada subtask individualmente contra estes critérios.",
      "items": { "type": "string" },
      "minItems": 1
    },
    "assigned_agent": {
      "type": "string",
      "description": "Slug do agente executor responsável. Deve corresponder a um agents_config.id.",
      "examples": ["backend-specialist", "frontend-specialist", "filament-specialist", "database-specialist"]
    },
    "files_to_create_or_modify": {
      "type": "array",
      "description": "Lista EXPLÍCITA de arquivos que este subagente vai criar ou modificar. Essencial para o FileLockManager gerenciar conflitos entre subtasks paralelas.",
      "items": {
        "type": "object",
        "required": ["path", "action"],
        "properties": {
          "path": {
            "type": "string",
            "description": "Caminho absoluto do arquivo.",
            "examples": ["/var/www/html/projetos/portal/app/Models/User.php"]
          },
          "action": {
            "type": "string",
            "enum": ["create", "modify", "delete"],
            "description": "'create' para arquivos novos, 'modify' para edições, 'delete' para remoção."
          }
        }
      },
      "minItems": 1
    },
    "dependencies_context": {
      "type": "string",
      "description": "Resumo do que as subtasks anteriores (dependências) já fizeram. Permite ao subagente entender o que já existe sem precisar ler tudo. Preenchido automaticamente pelo Orchestrator.",
      "default": ""
    },
    "constraints": {
      "type": "array",
      "description": "Restrições herdadas do PRD principal + restrições específicas desta subtask.",
      "items": { "type": "string" },
      "default": []
    },
    "tools_suggested": {
      "type": "array",
      "description": "Ferramentas que o Orchestrator sugere para esta subtask. O subagente pode usar outras se necessário.",
      "items": {
        "type": "string",
        "enum": ["BoostTool", "DocSearchTool", "FileReadTool", "FileWriteTool", "GitOperationTool", "ShellExecuteTool"]
      },
      "default": []
    }
  }
}
```

---

## 5. Exemplo: Como o Orchestrator Quebra um PRD em Sub-PRDs

Usando o exemplo do PRD de "Gestão de Usuários" acima, o Orchestrator geraria as seguintes subtasks:

### Subtask 1: Migration e Enum (database-specialist, execution_order: 1)

```json
{
  "title": "Criar migration de novos campos de perfil e Enum UserRole",
  "objective": "Criar uma migration que adiciona os campos avatar (string nullable), phone (string nullable) e role (enum com valores admin/editor/viewer, default viewer) à tabela users existente. Também criar o Enum PHP nativo App\\Enums\\UserRole com os valores correspondentes. A migration deve ser IDEMPOTENTE: verificar com Schema::hasColumn('users', 'avatar') antes de adicionar.",
  "acceptance_criteria": [
    "Arquivo database/migrations/YYYY_MM_DD_HHMMSS_alter_users_add_profile_fields.php criado",
    "Migration contém Schema::hasColumn() para idempotência",
    "Arquivo app/Enums/UserRole.php criado com cases: Admin, Editor, Viewer",
    "Enum possui método label() que retorna nomes em português (Administrador, Editor, Visualizador)",
    "php artisan migrate roda sem erros"
  ],
  "assigned_agent": "database-specialist",
  "files_to_create_or_modify": [
    {"path": "/var/www/html/projetos/portal/database/migrations/2026_04_09_000001_alter_users_add_profile_fields.php", "action": "create"},
    {"path": "/var/www/html/projetos/portal/app/Enums/UserRole.php", "action": "create"}
  ],
  "dependencies_context": "",
  "constraints": [
    "Migration deve ser idempotente — usar Schema::hasColumn()",
    "Enum deve usar backed enum (string) para compatibilidade com PostgreSQL"
  ],
  "tools_suggested": ["BoostTool", "FileWriteTool", "ShellExecuteTool"]
}
```

### Subtask 2: Model User (backend-specialist, execution_order: 2, depends_on: [Subtask 1])

```json
{
  "title": "Atualizar Model User com novos campos, casts e scopes",
  "objective": "Editar o Model User existente para incluir os novos campos de perfil (avatar, phone, role) no fillable, adicionar cast para role usando o Enum UserRole, criar accessor getAvatarUrlAttribute() que retorna a URL pública do avatar ou um placeholder, e criar scope scopeByRole($query, UserRole $role).",
  "acceptance_criteria": [
    "Campos avatar, phone, role adicionados ao $fillable",
    "Cast 'role' => UserRole::class adicionado ao $casts",
    "Accessor getAvatarUrlAttribute() funciona com e sem avatar (placeholder para null)",
    "Scope scopeByRole() permite filtrar User::byRole(UserRole::Admin)->get()",
    "O trait SoftDeletes já está presente (se não, adicionar)"
  ],
  "assigned_agent": "backend-specialist",
  "files_to_create_or_modify": [
    {"path": "/var/www/html/projetos/portal/app/Models/User.php", "action": "modify"}
  ],
  "dependencies_context": "A Subtask 1 já criou a migration que adiciona os campos avatar, phone e role à tabela users, e o Enum App\\Enums\\UserRole com cases Admin, Editor, Viewer.",
  "constraints": [
    "Usar Enum PHP nativa (App\\Enums\\UserRole) — não string mágica",
    "Avatar salvo em storage/app/public/avatars com disk 'public'"
  ],
  "tools_suggested": ["BoostTool", "FileReadTool", "FileWriteTool"]
}
```

### Subtask 3: Filament Resource (filament-specialist, execution_order: 3, depends_on: [Subtask 1, Subtask 2])

```json
{
  "title": "Criar UserResource completo no Filament v5 com form, table, filtros e ações",
  "objective": "Gerar o UserResource no Filament v5 usando php artisan make:filament-resource User --generate, e depois customizar: FormSchema com TextInput name (required), TextInput email (required, unique exceto current), TextInput phone (tel mask), Select role (usando Enum UserRole), FileUpload avatar. TableColumns com ImageColumn avatar, TextColumn name (searchable sortable), TextColumn email (searchable), TextColumn role com BadgeColumn e cores por status, TextColumn created_at (sortable). Adicionar SelectFilter por role, TrashedFilter, BulkDeleteAction, RestoreAction e ExportAction no header.",
  "acceptance_criteria": [
    "Comando php artisan make:filament-resource User --generate executado com sucesso",
    "FormSchema com todos os 5 campos configurados corretamente",
    "TableColumns com todos os 5 colunas configuradas",
    "SelectFilter por role usando Enum UserRole",
    "TrashedFilter habilitado",
    "BulkAction de delete presente",
    "Ação Restaurar visível apenas para soft-deleted",
    "ExportAction no header para CSV",
    "A página do Resource carrega sem erros no browser"
  ],
  "assigned_agent": "filament-specialist",
  "files_to_create_or_modify": [
    {"path": "/var/www/html/projetos/portal/app/Filament/Resources/UserResource.php", "action": "create"},
    {"path": "/var/www/html/projetos/portal/app/Filament/Resources/UserResource/Pages/ListUsers.php", "action": "create"},
    {"path": "/var/www/html/projetos/portal/app/Filament/Resources/UserResource/Pages/CreateUser.php", "action": "create"},
    {"path": "/var/www/html/projetos/portal/app/Filament/Resources/UserResource/Pages/EditUser.php", "action": "create"}
  ],
  "dependencies_context": "A Subtask 1 criou a migration e o Enum UserRole. A Subtask 2 atualizou o Model User com fillable, casts, accessor de avatar e scope byRole. Todos os campos já existem no banco e no Model.",
  "constraints": [
    "Usar exclusivamente FormBuilder e TableBuilder do Filament v5",
    "Não criar rotas manuais — usar auto-routing do Resource",
    "Avatar FileUpload deve usar disk 'public' e directory 'avatars'"
  ],
  "tools_suggested": ["BoostTool", "ShellExecuteTool", "FileWriteTool"]
}
```

### Subtask 4: Testes Pest (backend-specialist, execution_order: 4, depends_on: [Subtask 1, Subtask 2, Subtask 3])

```json
{
  "title": "Criar suite de testes Pest para UserResource cobrindo todos os cenários CRUD",
  "objective": "Criar testes Pest que cobrem: criação de usuário com dados válidos, criação com email duplicado (deve falhar validação), edição de usuário existente, deleção (soft delete), restauração de usuário deletado, listagem com filtro por role. Todos os testes devem usar RefreshDatabase e factories.",
  "acceptance_criteria": [
    "Arquivo tests/Feature/UserResourceTest.php criado",
    "Teste it('can create a user') com assertDatabaseHas",
    "Teste it('cannot create user with duplicate email') com assertSessionHasErrors",
    "Teste it('can edit a user') alterando name e role",
    "Teste it('can delete a user') verificando soft delete (assertSoftDeleted)",
    "Teste it('can restore a deleted user')",
    "Teste it('can filter users by role') usando SelectFilter",
    "php artisan test --filter=UserResourceTest passa com 0 failures"
  ],
  "assigned_agent": "backend-specialist",
  "files_to_create_or_modify": [
    {"path": "/var/www/html/projetos/portal/tests/Feature/UserResourceTest.php", "action": "create"}
  ],
  "dependencies_context": "As Subtasks 1-3 já criaram: migration com campos avatar/phone/role, Enum UserRole, Model User atualizado, e UserResource completo no Filament v5 com form, table, filtros e ações.",
  "constraints": [
    "Usar Pest (não PHPUnit puro) com sintaxe it('...')",
    "Usar RefreshDatabase trait",
    "Usar User::factory() para criação de dados"
  ],
  "tools_suggested": ["BoostTool", "FileWriteTool", "ShellExecuteTool"]
}
```

---

## 6. PRDs Gerados Automaticamente (Pelo Sentinela)

Quando o Sentinela detecta um erro em runtime, ele gera um PRD automaticamente com formato específico:

```json
{
  "title": "[SENTINEL] QueryException em PostController.php:89",
  "objective": "O Sentinela detectou uma QueryException em runtime. O erro ocorreu na linha 89 do arquivo PostController.php ao tentar executar uma query com relação 'comments.author' que não existe no Model. O request que causou o erro foi GET /posts/5 com user_id=12. O sistema precisa corrigir o erro para que a rota funcione sem exceções.",
  "acceptance_criteria": [
    "A rota GET /posts/{id} funciona sem exceções",
    "A relação 'comments.author' está corretamente definida no Model ou a query foi ajustada",
    "php artisan test não mostra regressões"
  ],
  "constraints": [
    "NÃO silenciar o erro com try/catch — CORRIGIR a causa raiz",
    "NÃO remover funcionalidade existente para 'resolver' o problema"
  ],
  "knowledge_areas": ["backend", "database"],
  "context": {
    "error_stack_trace": "Illuminate\\Database\\QueryException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'comments.author_id' in 'where clause' (Connection: mysql, SQL: select * from `comments` where `comments`.`author_id` = 12 and `comments`.`author_id` is not null)\n\n  at /var/www/html/projetos/portal/app/Http/Controllers/PostController.php:89\n  at /var/www/html/projetos/portal/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825",
    "related_files": [
      "/var/www/html/projetos/portal/app/Http/Controllers/PostController.php",
      "/var/www/html/projetos/portal/app/Models/Post.php",
      "/var/www/html/projetos/portal/app/Models/Comment.php"
    ],
    "related_tables": ["posts", "comments"]
  },
  "priority_hint": "critical",
  "estimated_complexity": "simple"
}
```

**Observação:** O Sentinela preenche automaticamente o `error_stack_trace`, `related_files` (extraídos do stack trace) e `related_tables` (inferidas a partir dos nomes de Model no trace). O humano NÃO precisa intervir — o PRD é gerado e inserido como task de prioridade máxima (100) automaticamente.

---

## 7. Validação do PRD (PRDValidator.php)

O `PRDValidator.php` verifica:

1. **Campos obrigatórios presentes** — `title`, `objective`, `acceptance_criteria`, `knowledge_areas`
2. **Tipos corretos** — `acceptance_criteria` é array, `knowledge_areas` contém apenas enums válidos
3. **Restrições de tamanho** — `title` >= 10 chars, `objective` >= 50 chars, pelo menos 1 critério
4. **Enums válidos** — `knowledge_areas` contém apenas valores do enum `KnowledgeArea`
5. **Paths válidos** — `related_files` começam com `/var/www/html/projetos/`
6. **Dedup** — Verifica se já existe task `pending` ou `in_progress` com título igual ou hash similar

Se qualquer validação falhar, o PRDValidator retorna um array de erros detalhados:

```json
{
  "valid": false,
  "errors": [
    {"field": "acceptance_criteria", "message": "Deve conter pelo menos 1 critério de aceite"},
    {"field": "knowledge_areas[2]", "message": "Valor 'devopsx' inválido. Valores permitidos: backend, frontend, database, filament, devops, testing, design"}
  ]
}
```
