# Guia do Painel Administrativo AI-Dev (Filament v5)

---

> [!NOTE]
> **Versão 2.0 — Granularidade Progressiva (2026-04-23)**
>
> Alterações realizadas na estrutura de navegação e fluxo do painel:
>
> 1. **Bloco de desenvolvimento autônomo (menu raiz)** — ordem definitiva:
>    - `Projetos` (sort=1) — ponto de entrada de tudo
>    - `Módulos` (sort=2) — hierarquia progressiva: módulos → submódulos (se necessário)
>    - `Tarefas` (sort=3) — unidades de trabalho em nós folha
>
> 2. **Fluxo de Granularidade Progressiva (ativo):**
>    - Projeto → Gerar PRD do Projeto → Aprovar → cria **apenas módulos raiz**
>    - Módulo → Gerar PRD do Módulo → decide `needs_submodules`
>    - Se `needs_submodules = true` → **"✅ Aprovar PRD — Criar Submódulos"** → repetir processo
>    - Se `needs_submodules = false` → **"✅ Aprovar PRD — Criar Tasks"** → pipeline de execução
>
> 3. **`Especificações` removida do menu lateral** (`$shouldRegisterNavigation = false`).
>    - O fluxo ativo usa `projects.prd_payload` + `project_modules.prd_payload` em vez de `project_specifications`.
>    - O resource `ProjectSpecificationResource` ainda existe para retrocompatibilidade.
>
> 4. **Navegação e Layout:**
>    - Breadcrumbs hierárquicos em todas as páginas View (Projeto, Módulo, Task)
>    - Links cruzados entre recursos (clicar no projeto na tabela de módulos, etc.)
>    - Aba "Módulos do Projeto" no ViewProject com tabela linkável
>    - Layout 100% largura — sections empilhadas verticalmente

---


Este documento descreve o funcionamento do **Admin Panel do ai-dev-core** (Master), localizado em `/ai-dev-core/public/admin`. É daqui que o humano cadastra Projetos Alvo, cria tasks, dispara cotações e acompanha execução dos agentes.

> **Dois Admin Panels coexistem no ecossistema — não confundir:**
> - **Admin Panel do ai-dev-core** *(este guia)* — gere `projects` (cadastro dos alvos), `tasks`, `subtasks`, `agents_config`, `project_quotations`. É aqui que as IAs de interação do Master (`RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent`) falam com o usuário para estruturar o PRD antes da execução.
> - **Admin Panel de cada Projeto Alvo** *(documentado no repositório de cada alvo, não aqui)* — gere as entidades de negócio daquele projeto (ex: clientes, pedidos, usuários finais) e hospeda as IAs de interação específicas daquele projeto (copiloto do usuário, classificador, etc.). O ai-dev-core **não** opera este painel — ele apenas gera o código dele.
>
> Para a tabela canônica de separação entre ai-dev-core e Projeto Alvo, veja `README.md → Arquitetura em Duas Camadas`.

## 1. Gestão de Projetos (`Projects`)
O ponto de partida para qualquer automação. Cada linha em `projects` representa um **Projeto Alvo** — uma aplicação Laravel externa, com repositório próprio, banco próprio e Boost MCP próprio, que o ai-dev-core vai desenvolver/refatorar/manter.

### 1.1 Fluxo de Criação e PRD Master

O sistema adota **granularidade progressiva** — o PRD do projeto gera apenas os módulos de alto nível. Submódulos e tasks são decididos nos níveis subsequentes.

**Passo a passo:**

1. **Criar Projeto** — preencha `name`, `github_repo`, `local_path`, `db_password`
2. **Descrever o Projeto** — na aba "Descrição do Projeto", escreva livremente o que o sistema deve fazer. Use "Refinar com IA" se quiser melhorar o texto.
3. **Gerar PRD do Projeto** — na página de visualização do projeto, clique no botão **"Gerar PRD do Projeto"** (header da página). O `ProjectPrdAgent` analisa a descrição + funcionalidades e gera o PRD Master com os módulos de alto nível. O PRD também pode ser gerado via a aba "PRD do Projeto" no formulário de edição. **Isso pode levar alguns minutos.**
4. **Aprovar PRD** — quando o PRD aparecer, revise os módulos listados e clique em **"✅ Aprovar PRD — Criar Módulos"** (header da página de visualização). O sistema cria automaticamente os módulos raiz no banco e redireciona para a lista de módulos.
5. **Navegar para Módulos** — use a aba "Módulos do Projeto" no detalhe do projeto ou vá em **Módulos** no menu lateral.

- **Provedor e Modelo:** Todo o sistema agêntico do ai-dev-core é **configurável dinamicamente** via `Configuração > Sistema` (tabela `system_settings`). O `AiRuntimeConfigService` resolve provider, model e API key em runtime para cada um dos 4 tiers: Premium, High, Fast e System. Providers suportados: OpenRouter, Anthropic, OpenAI, **Kimi (Moonshot AI)** e Ollama.
- **Contexto Persistente:** O ai-dev-core armazena o ID de sessão (coluna `anthropic_session_id`) e as conversas (tabelas `agent_conversations`, `agent_conversation_messages`) **no banco do ai-dev-core** — nenhum dado desse tipo contamina o banco do alvo.

## 2. Estrutura de Módulos (`Modules`)
Os módulos permitem decompor um projeto complexo em partes menores e gerenciáveis. O sistema adota **granularidade progressiva**: cada módulo decide se precisa de submódulos.

### 2.1 Hierarquia (Módulos e Submódulos)
- O sistema suporta **hierarquia infinita**. Um módulo pode ter um "Módulo Pai".
- **Exemplo:** `Mensageria` > `WhatsApp` > `Caixa de Entrada`.
- **Regra de ouro:** Tasks são criadas **apenas nos nós folha** (módulos/submódulos sem filhos).

### 2.2 Fluxo de Trabalho por Módulo (Granularidade Progressiva)

Após aprovar o PRD do projeto, cada módulo raiz precisa passar pelo seguinte:

1. **Entrar no Módulo** — clique no nome do módulo para abrir a página de visualização
2. **Gerar PRD do Módulo** — clique em "Gerar PRD do Módulo". O `ModulePrdAgent` gera um PRD técnico detalhado (schema, APIs, workflows, critérios). **Isso pode levar vários minutos.**
3. **Decisão automática:**
   - Se o PRD indicar `needs_submodules = true` → aparece o botão **"✅ Aprovar PRD — Criar Submódulos"**
   - Se o PRD indicar `needs_submodules = false` → aparece o botão **"✅ Aprovar PRD — Criar Tasks"**
4. **Se criar submódulos:** cada submódulo segue o mesmo processo (entra → gera PRD → decide)
5. **Se criar tasks:** o sistema gera tasks automaticamente a partir do PRD técnico (componentes, APIs, migrations, testes)

### 2.3 Dependências Estritamente Consolidadas
- Um módulo pode depender de outros módulos do mesmo projeto.
- **Regra de Seleção:** O sistema só permite selecionar como dependência módulos que já estejam com o status **Concluído** (`Completed`).
- Isso garante que a base de código onde o novo módulo será construído está estável e testada.

### 2.4 Navegação entre Módulos
- **Breadcrumb no topo:** toda página de módulo mostra a trilha completa: `📁 Projeto / Módulo Pai / ... / Módulo Atual`
- **Links clicáveis:** o nome do Projeto e do Módulo Pai são links que levam às respectivas páginas
- **Título dinâmico na lista:** quando navegando dentro de um módulo, o título da página muda para "Submódulos de: {nome do módulo}" para deixar claro o contexto
- **Aba "Tasks do Módulo":** mostra as tasks do módulo atual com link direto para cada task

## 3. Gestão de Tarefas (`Tasks`)
As tarefas são as unidades de trabalho executadas pelos **agentes de desenvolvimento** do ai-dev-core. Cada task referencia um `project_id` e um `module_id` — e todo o trabalho concreto (leitura de código, escrita, testes, commits) acontece no `local_path` daquele Projeto Alvo, **nunca** no filesystem do ai-dev-core.

> **Regra:** Tasks são criadas **apenas em módulos folha** (módulos/submódulos que não têm filhos). O botão **"✅ Aprovar PRD — Criar Tasks"** só aparece quando o PRD do módulo define `needs_submodules = false`.

### 3.1 Classificação de Prioridade
A prioridade não é mais um número confuso, mas sim uma classificação semântica:
- **Padrão (Normal):** Fluxo comum de desenvolvimento.
- **Média (Medium):** Tarefas com prazos moderados ou importância intermediária.
- **Alta (High):** Tarefas críticas ou bloqueantes.
- **Ordenação:** Internamente, o sistema processa primeiro as de maior prioridade seguindo a data de criação (mais antigas primeiro).

### 3.2 Vinculação e Fluxo
- Uma task pode ser avulsa (hotfix, Sentinela) ou vinculada a um **Módulo Folha**.
- O fluxo segue uma máquina de estados rigorosa: `pending` → `in_progress` → `qa_audit` → `testing` → `completed` / `rejected` → `rollback` → `failed`.

### 3.3 Navegação da Task
- **Breadcrumb no topo:** `📁 Projeto / 🔲 Módulo / 📋 Tarefa Atual`
- **Links clicáveis:** o nome do Projeto e do Módulo levam às respectivas páginas
- **Aba "Subtasks":** mostra as subtasks geradas pelo Orchestrator para esta task
- **Layout vertical:** Visão Geral, PRD, Execução, Subtasks, Histórico e Log de Erros — cada section ocupa 100% de largura

## 4. Orçamentos e Custos (`Quotations`)
Localizado no grupo "Configuração", permite estimar o custo humano vs. custo AI-Dev.
- **Custo Real:** O sistema rastreia em tempo real o consumo de tokens (USD) e infraestrutura (BRL) de cada projeto, acumulando os valores na cotação ativa.

## 5. Áreas de Conhecimento
Ao criar módulos ou tarefas, você define quais especialistas serão convocados:
- `backend`, `frontend`, `database`, `filament`, `devops`, `testing`, `design`.

## 6. Inteligência Híbrida e Contexto Dinâmico
O AI-Dev utiliza um sistema de **Context Awareness** (Ciência de Contexto) em tempo real — lendo sempre do **Projeto Alvo**, não do ai-dev-core.
- **Detecção via Boost do alvo:** o `BoostTool` (a ser tornado project-path-aware — ver README, Fase 1) executa `php artisan boost:*` dentro do `local_path` do alvo e retorna schema, docs instaladas e estado do código daquele projeto.
- **Sincronização da Stack:** As versões exatas de Laravel, Filament, Livewire, Tailwind e Anime.js **instaladas no Projeto Alvo** são a fonte de verdade — não as versões do ai-dev-core. Projetos podem divergir em versão de pacote e cada um é tratado pelo seu próprio Boost.
- **Benefício:** A IA nunca sugerirá sintaxe de Filament v4 para um projeto com Filament v5, nem vice-versa — mesmo que o ai-dev-core esteja em versão diferente.

## 7. Animações com Anime.js
O ambiente web e os projetos gerados contam com a biblioteca **Anime.js v4** integrada nativamente.
- **Acesso Global:** Disponível via `window.anime` em qualquer componente Livewire ou Script Alpine.js.
- **Uso no Refinamento:** O Agente de IA está instruído a considerar o Anime.js para propostas de interfaces modernas e dinâmicas.

## 8. Assistente IA-Dev (Chat do Dashboard)
O widget `DashboardChat` é um componente Livewire embarcado na página inicial do painel administrativo. Ele oferece um chat com IA para os usuários do sistema.

### 8.1 Arquitetura
- **Componente:** `App\Filament\Widgets\DashboardChat` + blade `filament.widgets.dashboard-chat`
- **Agente:** Utiliza o `SystemAssistantAgent`, que possui instruções de sistema (system prompt) rígidas em português.
- **Modelo:** Configurado via `SystemSetting` (chaves `ai_system_provider` e `ai_system_model`). Não há mais fallback hardcoded — o sistema usa obrigatoriamente os valores configurados na UI de Configuração do Sistema.

### 8.2 Persistência da Conversa
- O histórico é salvo na **sessão PHP** (não no banco), sobrevivendo à navegação entre páginas.
- Limitado a **40 mensagens** (20 trocas) para controlar o tamanho do contexto enviado ao agente.
- O botão "Limpar" reseta a sessão e reinicia do zero.

### 8.3 Controle de Interface (Alpine.js + wire:loading)
- O textarea usa **Alpine `x-model`** (variável `localMsg`), desacoplado do `wire:model` do Livewire. Isso permite limpeza instantânea do campo ao enviar, sem race conditions.
- O envio é feito via `$wire.sendMessage(localMsg)` — a mensagem é passada como parâmetro direto.
- O indicador de digitação (bouncing dots) é controlado por `wire:loading wire:target="sendMessage"`, usando um wrapper duplo (div externo `display:none`/`block`, div interno `display:flex`).
- O scroll automático usa `setTimeout(80ms)` para garantir que o `wire:loading` já renderizou os pontinhos antes do scroll.

### 8.4 Segurança (Sandboxing)
O `SystemAssistantAgent` possui restrições explícitas no system prompt:
- **Proibido:** revelar estrutura do banco de dados, caminhos de arquivos, configurações `.env`, chaves API, credenciais ou detalhes de arquitetura.
- **Permitido:** informações sobre projetos, módulos, tarefas, dados cadastrados e uso funcional do sistema.

## 9. Administração

### 9.1 Perfis de Usuários (Roles)
Módulo baseado no **Filament Shield** (`BezhanSalleh\FilamentShield`). Permite criar e gerenciar perfis de acesso (roles) com permissões granulares por Resource/Page/Widget.

- **Resource:** `App\Filament\Resources\RoleResource` (estende `BaseRoleResource` do Shield)
- **Rótulos:** Exibido como "Perfis de Usuários" na navegação, grupo "Administração"
- **Roles padrão:** `super_admin` (acesso total), `developer` (desenvolvimento), demais criados conforme necessidade

### 9.2 Usuários
Gerencia os usuários com acesso ao painel administrativo.

- **Resource:** `App\Filament\Resources\Users\UserResource`
- **Funcionalidades:**
  - CRUD de usuários (nome, e-mail, senha)
  - Atribuição de múltiplos Perfis de Acesso (roles) via select searchable
  - Senha com `dehydrated(fn ($state) => filled($state))` — só atualiza se preenchida na edição
  - Coluna de roles exibida como badges na listagem
- **Model:** `App\Models\User` — implementa `FilamentUser`, `HasRoles` (Spatie Permission)
- **Controle de Acesso:** `canAccessPanel()` retorna `true` para todos, com permissões granulares via Shield

### 9.3 Logs de Atividades
Módulo de auditoria que registra automaticamente todas as ações CRUD em todos os Models do sistema.

- **Resource:** `App\Filament\Resources\ActivityLogs\ActivityLogResource`
- **Pacote:** `spatie/laravel-activitylog` v4.12
- **Somente leitura:** `canCreate()`, `canEdit()` e `canDelete()` retornam `false`

#### 9.3.1 Registro Automático (LogsActivity)
Todos os Models principais usam a trait `LogsActivity` do Spatie, que registra automaticamente operações `created`, `updated` e `deleted`:

| Model | Log Name | Descrição gerada |
|---|---|---|
| `Project` | default | "Projeto created/updated/deleted" |
| `Task` | default | "Tarefa created/updated/deleted" |
| `ProjectModule` | default | "Módulo created/updated/deleted" |
| `ProjectSpecification` | default | "Especificação created/updated/deleted" |
| `ProjectQuotation` | default | "Orçamento created/updated/deleted" |
| `AgentConfig` | default | "Agente created/updated/deleted" |
| `SocialAccount` | default | "Conta social created/updated/deleted" |
| `User` | default | Registrado via Spatie |

Configuração em cada Model:
```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logAll()          // Loga todos os atributos
        ->logOnlyDirty()   // Só registra campos que mudaram
        ->setDescriptionForEvent(fn (string $eventName) => "Projeto {$eventName}");
}
```

#### 9.3.2 Filtros Dinâmicos
Os filtros da listagem são **dinâmicos**, carregados direto do banco:
- **Módulo (subject_type):** `SELECT DISTINCT subject_type FROM activity_log` — qualquer novo Model adicionado aparece automaticamente
- **Evento:** Criação, Atualização, Exclusão
- **Usuário:** Select do relacionamento `causer`

#### 9.3.3 Tradução de Models
Os FQCNs (`App\Models\Project`) são traduzidos para nomes amigáveis em PT-BR via mapa estático no Resource:
```php
'App\Models\Project' => 'Projeto',
'App\Models\Task'    => 'Tarefa',
// ... fallback: class_basename() para Models não mapeados
```

## 10. Configurações do Sistema
Página Filament (`SystemSettingsPage`) localizada em **Configuração > Sistema**.

### 10.1 Seções
| Seção | Campos | Descrição |
|---|---|---|
| Identidade do Sistema | Nome, Logotipo, Favicon | Identidade visual do painel |
| IA Nível PREMIUM | Provider, API Key, Modelo | Planejamento (ex: Claude Opus 4.7, Kimi K2.6) |
| IA Nível HIGH | Provider, API Key, Modelo | Desenvolvimento/QA (ex: Claude Sonnet 4.6, Kimi K2.6) |
| IA Nível FAST | Provider, API Key, Modelo | Documentação/Jobs (ex: Claude Haiku 4.5, GPT-4o-mini) |
| IA do Sistema | Provider, API Key, Modelo | Chat do dashboard (ex: Kimi K2.6, Claude Haiku) |
| Controle Operacional | Habilitar Agentes, Modo Manutenção | Controles de operação |

### 10.2 Persistência
Os valores são armazenados na tabela `system_settings` (chave-valor) com cache de 60 segundos. Model: `App\Models\SystemSetting`.

---
*Este manual reflete a versão 1.3 do sistema, com foco em auditoria, assistente IA e ciência de contexto.*

## 🛠 Histórico de Ajustes e Solução de Problemas

Se você encontrar erros ao operar o sistema, consulte esta seção de lições aprendidas:

### 1. Erro de Prioridade (`ValueError`)
- **Problema:** "X" is not a valid backing value for enum `App\Enums\Priority`.
- **Causa:** O sistema foi migrado de prioridades numéricas (10, 50, 90) para um Enum de string (`normal`, `medium`, `high`). Registros antigos com números travam a renderização.
- **Solução:** O sistema agora faz a conversão automática, mas em novos ambientes, certifique-se de que a coluna `priority` na migration seja `string` com default `normal`.

### 2. Erro de Coluna Inexistente (`order`)
- **Problema:** SQLSTATE[42703]: Undefined column: 7 ERROR: column "order" does not exist.
- **Causa:** A coluna `order` foi removida para simplificar a gestão pelos agentes (que agora usam prioridade + data).
- **Solução:** Remova qualquer `orderBy('order')` de Models, Resources ou Widgets. Use `orderBy('created_at', 'desc')` ou ordene pelo Enum de prioridade.

### 3. Erro de Tipagem no Refinamento IA (`TypeError`)
- **Problema:** Argument #1 ($form) must be of type `Filament\Forms\ComponentContainer`, `Filament\Schemas\Schema` given.
- **Causa:** No Filament v5 com a stack de Schemas, o container de componentes mudou de tipo.
- **Solução:** No método `mountUsing` de Actions de formulário, use sempre `\Filament\Schemas\Schema $form`.

### 4. Configuração de IA e Chaves API
- **Problema:** IA não responde ou erro de autenticação.
- **Causa:** Chave API incorreta, provider não configurado, ou `SystemSetting` vazio.
- **Solução:** Acesse `Configuração > Sistema` no painel e preencha os campos de Provider, API Key e Modelo para cada um dos 4 tiers (Premium, High, Fast, System). O sistema usa `AiRuntimeConfigService` para resolver esses valores em runtime a partir da tabela `system_settings`.
  - **Kimi (Moonshot AI):** Use `kimi` como provider e `kimi-k2.6` como model. A URL padrão é `https://api.kimi.com/coding/v1` (Kimi Code membership). O sistema gerencia automaticamente o `User-Agent` whitelistado e o `reasoning_content` do thinking mode.
  - **OpenRouter/Anthropic:** Use `openrouter` como provider e `anthropic/claude-sonnet-4.6` como model.
  - **OpenAI:** Use `openai` como provider e `gpt-4o` como model.

### 5. Configuração do MCP na IDE (Remoto via VPN)
- **Problema:** Conectar a IDE local (Cursor, Windsurf, Claude Desktop) ao servidor MCP do projeto rodando em um servidor remoto.
- **Solução:** Adicione um novo servidor MCP do tipo `command` nas configurações da IDE. O comando deve criar um túnel SSH e executar o boost silenciosamente.
- **Requisito:** A máquina local DEVE ter acesso via chave pública (SSH Keys) ao servidor remoto (ex: `ssh-copy-id root@10.1.1.86`). Se o SSH pedir senha, a IDE não conseguirá iniciar o MCP.
- **Comando de Exemplo:** `ssh root@10.1.1.86 "cd /var/www/html/projetos/ai-dev/ai-dev-core && php artisan boost:mcp"`

