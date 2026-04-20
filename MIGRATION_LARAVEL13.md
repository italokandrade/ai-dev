# Plano de Migracao AI-Dev: Laravel 12 → Laravel 13

> **Data:** 2026-04-10
> **Status:** ✅ CONCLUÍDA — O AI-Dev Core já roda Laravel 13 + Laravel AI SDK v0.5.1
> **Laravel 13 Release:** 17 de Marco de 2026
>
> Este documento está arquivado para referência histórica. Todos os itens foram executados.
> O projeto em `/var/www/html/projetos/ai-dev/ai-dev-core/` já usa Laravel 13, Filament v5,
> PostgreSQL 16 + pgvector, Redis 7, Laravel AI SDK (`laravel/ai`), MCP (`laravel/mcp`),
> Boost (`laravel/boost`) e Laravel Horizon com 4 supervisors configurados.

## 0. Contexto desta Migração — Apenas o ai-dev-core

Esta migração atualizou **exclusivamente o ai-dev-core** (Master). **Não** tocou em Projetos Alvo. Cada Projeto Alvo é uma aplicação Laravel independente — com seu próprio `composer.lock`, seu próprio `artisan`, seu próprio banco — e sua migração de Laravel é decidida e executada pelos agentes do ai-dev-core em uma task, quando e se fizer sentido para aquele projeto. O ai-dev-core em Laravel 13 **pode orquestrar** Projetos Alvo que ainda estão em Laravel 12, 11 ou versões anteriores, pois o `BoostTool` consulta a versão real do alvo antes de gerar código. A separação canônica entre as duas camadas vive em `README.md → Arquitetura em Duas Camadas`.

---

## 1. Analise de Impacto: O que o Laravel 13 nos da "de graca"

O Laravel 13 introduz o **Laravel AI SDK** como pacote first-party estavel, trazendo nativamente quase toda a infraestrutura que estavamos construindo "na unha". Este documento mapeia cada componente do AI-Dev contra o equivalente nativo do Laravel 13.

### 1.1. Mapa de Sobreposicao Completo

| Componente AI-Dev (Custom) | Equivalente Laravel 13 (Nativo) | Impacto | Acao |
|---|---|---|---|
| `ToolInterface` + `ToolRouter` | `Laravel\Ai\Contracts\Tool` + tool calling nativo | **ELIMINAR** | Migrar Tools para `Tool` contract do SDK |
| `LLMGateway` (multi-provider) | `Laravel\Ai\Enums\Lab` + `config/ai.php` | **ELIMINAR** | Provider-agnostic nativo com failover |
| `PromptFactory` | `Agent::instructions()` + `messages()` | **SIMPLIFICAR** | Manter logica de cache blocks, delegar montagem ao SDK |
| `OrchestratorJob` | Agent class + Prompt Chaining pattern | **REFATORAR** | Virar Agent class com `HasTools` + `HasStructuredOutput` |
| `SubagentJob` | Agent class + Orchestrator-Workers pattern | **REFATORAR** | Cada especialista vira uma Agent class |
| `QAAuditJob` | Agent class + Evaluator-Optimizer pattern | **REFATORAR** | Virar Agent class com structured output (approved/issues) |
| `agents_config` (tabela DB) | Agent classes em `app/Ai/Agents/` | **HIBRIDO** | Manter tabela para config dinamica, mas logica nos Agent classes |
| `session_history` + `ContextManager` | `RemembersConversations` trait + `agent_conversations` table | **ELIMINAR** | Conversa persistida nativamente pelo SDK |
| RAG vetorial (ChromaDB/SQLite-Vec) | `whereVectorSimilarTo()` + `Str::toEmbeddings()` + pgvector | **ELIMINAR** | Busca vetorial nativa no PostgreSQL via query builder |
| `problems_solutions` (tabela) | Coluna `vector` no PostgreSQL + `whereVectorSimilarTo()` | **SIMPLIFICAR** | Manter tabela, usar query builder vetorial nativo |
| Embeddings via Ollama | `Str::of('...')->toEmbeddings()` ou `Ai::embed()` | **AVALIAR** | Pode usar Ollama local via config ou providers cloud |
| Compressao de contexto (Ollama) | `RemembersConversations` + gestao automatica | **SIMPLIFICAR** | SDK gerencia contexto; manter compressao como otimizacao |
| JSON custom para tool schemas | `JsonSchema` builder nativo | **ELIMINAR** | Usar `$schema->string()->required()` etc. |
| `ToolResult` custom | Return `string` do `execute()` / `handle()` | **ELIMINAR** | Tools retornam string diretamente |
| Proxy Gemini/Claude custom | `config/ai.php` com driver `openai` + base_url OpenRouter | **REMOVIDO** | Proxies Python legados foram descontinuados. Todo o tráfego LLM sai direto do SDK para `https://openrouter.ai/api/v1` — rate limiting e logging ficam no OpenRouter + `agent_executions` |
| `context_library` (few-shot) | `instructions()` + injecao no prompt | **MANTER** | Padroes TALL continuam sendo injetados via instructions |
| `task_transitions` (auditoria) | Events do SDK (`AgentPrompted`, etc.) | **COMPLEMENTAR** | Manter tabela + ouvir eventos do SDK |
| `tool_calls_log` | Events do SDK + middleware | **SIMPLIFICAR** | Usar middleware do SDK para logging |
| Filament Dashboard | Filament Dashboard | **MANTER** | Sem mudanca, dados vem das mesmas tabelas |
| Queue (Horizon + Supervisor) | `Queue::route()` + `agent->queue()->prompt()` | **SIMPLIFICAR** | Queue routing centralizado + agents queueable nativos |
| Git branching por task | Git branching por task | **MANTER** | Logica customizada, SDK nao cobre isso |
| Sentinel (Self-Healing) | Sentinel (Self-Healing) | **MANTER** | Logica customizada, SDK nao cobre isso |
| Circuit Breakers | Middleware do SDK + logica custom | **COMPLEMENTAR** | Usar middleware para rate limiting, manter limites custom |

### 1.2. Resumo do Impacto

| Categoria | Quantidade | Detalhes |
|---|---|---|
| **ELIMINAR** (substituir por nativo) | 8 componentes | ToolInterface, ToolRouter, LLMGateway, ContextManager, session_history, RAG engine, JSON schemas, ToolResult |
| **REFATORAR** (adaptar ao SDK) | 4 componentes | OrchestratorJob, SubagentJob, QAAuditJob, PromptFactory |
| **SIMPLIFICAR** (menos codigo) | 4 componentes | problems_solutions, compressao, tool_calls_log, Queue config |
| **MANTER** (sem mudanca) | 6 componentes | Git branching, Sentinel, Filament, Proxies, context_library, task_transitions |
| **COMPLEMENTAR** (SDK + custom) | 2 componentes | Events + transitions, Circuit breakers + middleware |

**Estimativa de reducao de codigo custom:** ~60-70% do codigo que planejamos escrever agora e nativo.

---

## 2. Nova Arquitetura com Laravel 13 AI SDK

### 2.1. Diagrama Atualizado

```text
+----------------------------------------------------------------------+
|                    AI-DEV CORE (Laravel 13)                           |
|                                                                      |
|  +------------+   +----------------+   +---------------------------+ |
|  | Filament v5 |   | Laravel AI SDK  |   | Tool Layer               | |
|  | (Web UI)    |   | (Agents/Tools)  |   | (Tool contract nativo)   | |
|  +------+------+   +-------+--------+   +------------+-------------+ |
|         |                  |                         |                |
|  +------v------------------v-------------------------v--------------+|
|  |                PostgreSQL 16 + pgvector (Estado Central)          ||
|  |  projects | tasks | subtasks | agents_config | agent_conversations||
|  |  problems_solutions (com coluna vector)                           ||
|  +----------------------------+--------------------------------------+|
|                               |                                      |
|  +----------------------------v--------------------------------------+|
|  |         Laravel Queue + Redis + Queue::route()                    ||
|  |                                                                   ||
|  |  +------------------+  +----------------+  +--------------------+ ||
|  |  | OrchestratorAgent |  | QAAuditorAgent |  | Specialist Agents  | ||
|  |  | (Planner)         |  | (Judge)        |  | (Executors)        | ||
|  |  +------------------+  +----------------+  +--------------------+ ||
|  +-------------------------------------------------------------------+|
|                                                                      |
|  +------------------------------------------------------------------+|
|  |   AI Providers (via config/ai.php)                                ||
|  |   'openrouter' (driver openai) | Lab::Ollama (local)              ||
|  |   (Provider-agnostic, failover automatico)                        ||
|  +------------------------------------------------------------------+|
|                                                                      |
|  +------------------------+  +--------------------------------------+|
|  | pgvector (Embeddings   |  | Sentinel (Self-Healing Runtime)      ||
|  |  + Semantic Search)    |  | (Exception Handler Customizado)      ||
|  +------------------------+  +--------------------------------------+|
+----------------------------------------------------------------------+
```

### 2.2. O que Muda no Diagrama

| Antes (Laravel 12) | Depois (Laravel 13) |
|---|---|
| `Tool Layer (MCP) - Plugins Isolados` | `Tool Layer (Tool contract nativo)` |
| `Prompt Factory` (servico custom) | `Agent::instructions()` + `messages()` |
| `ChromaDB/SQLite-Vec (Memoria Vetorial)` | `pgvector (Embeddings + Semantic Search)` |
| `Motores LLM (Inferencia Dupla)` | `AI Providers (via config/ai.php)` |
| `OrchestratorJob` (Laravel Job) | `OrchestratorAgent` (Agent class) |
| `SubagentJob` (Laravel Job) | `Specialist Agents` (Agent classes) |
| `QAAuditJob` (Laravel Job) | `QAAuditorAgent` (Agent class) |

---

## 3. Mapeamento Detalhado: Componentes Custom → Laravel 13

### 3.1. Tools: `ToolInterface` → `Laravel\Ai\Contracts\Tool`

**Antes (AI-Dev custom):**
```php
interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function outputSchema(): array;
    public function execute(array $params): ToolResult;
}
```

**Depois (Laravel 13 AI SDK):**
```php
use Laravel\Ai\Concerns\ToolUse;
use Laravel\Ai\Contracts\Tool;

class ShellTool implements Tool
{
    use ToolUse;

    public function description(): Stringable|string
    {
        return 'Executar comandos no terminal do servidor de forma controlada.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['execute', 'execute_background', 'kill'])->required(),
            'command' => $schema->string()->required(),
            'working_directory' => $schema->string(),
            'timeout_seconds' => $schema->integer()->min(5)->max(600),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        // Logica de execucao (mesma de antes)
        return json_encode($result);
    }
}
```

**Mudancas:**
- `name()` → nome inferido da classe
- `inputSchema()` → `schema(JsonSchema $schema)` com builder fluent
- `outputSchema()` → eliminado (retorno e string)
- `execute(array)` → `handle(Request $request)`
- `ToolResult` → retorno `string` direto
- `ToolRouter` → **eliminado**, SDK roteia automaticamente

### 3.2. Agentes: Jobs → Agent Classes

**Antes (OrchestratorJob):**
```php
class OrchestratorJob implements ShouldQueue
{
    public function handle(LLMGateway $gateway, PromptFactory $factory)
    {
        $prompt = $factory->build($this->task, 'orchestrator');
        $response = $gateway->send('claude', $prompt);
        $subPrds = json_decode($response);
        // criar subtasks...
    }
}
```

**Depois (OrchestratorAgent):**
```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class OrchestratorAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(public Task $task) {}

    public function instructions(): string
    {
        return 'Voce e o Orchestrator do sistema AI-Dev. Sua unica
                responsabilidade e PLANEJAR. Analise o PRD e retorne
                uma lista de Sub-PRDs em formato JSON...';
    }

    public function tools(): iterable
    {
        return [
            new CreateSubtaskTool,
            new AnalyzeProjectTool,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subtasks' => $schema->array()->items([
                'title' => $schema->string()->required(),
                'assigned_agent' => $schema->string()->required(),
                'sub_prd' => $schema->object()->required(),
                'dependencies' => $schema->array()->items($schema->string()),
                'execution_order' => $schema->integer()->required(),
            ])->required(),
        ];
    }
}

// Uso:
$plan = OrchestratorAgent::make(task: $task)
    ->prompt($task->prd_payload, provider: 'openrouter', model: 'anthropic/claude-opus-4.7');

foreach ($plan['subtasks'] as $subtask) {
    Subtask::create($subtask);
}
```

### 3.3. LLMGateway → config/ai.php

**Antes (LLMGateway custom com múltiplos providers + proxies Python):**
```php
class LLMGateway
{
    public function send(string $provider, string $prompt): string
    {
        return match($provider) {
            'gemini' => $this->sendToGemini($prompt),  // proxy :8001
            'claude' => $this->sendToClaude($prompt),  // proxy :8002
            'ollama' => $this->sendToOllama($prompt),
        };
    }
}
```

**Depois (config/ai.php — OpenRouter como gateway único + Ollama local):**
```php
// config/ai.php
'providers' => [
    'openrouter' => [
        'driver' => 'openai',
        'key'    => env('OPENROUTER_API_KEY'),
        'url'    => 'https://openrouter.ai/api/v1',
    ],
    'ollama' => [
        'driver' => 'ollama',
        'key'    => env('OLLAMA_API_KEY', ''),
        'url'    => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    ],
    'openrouter_chain' => [
        'driver'    => 'failover',
        'providers' => ['openrouter', 'openai'],
    ],
],

// Uso direto no agent (o atributo #[Provider('openrouter')] já aplica o default):
$response = OrchestratorAgent::make(task: $task)
    ->prompt($prd);

// Override em runtime (provider como string — openrouter é alias em config/ai.php, NÃO Lab::):
$response = OrchestratorAgent::make(task: $task)
    ->prompt($prd, provider: 'openrouter', model: 'anthropic/claude-opus-4.7');

// Failover em runtime (per ai-sdk.md §Failover — array só no parametro prompt, nunca no atributo):
$response = OrchestratorAgent::make(task: $task)
    ->prompt($prd, provider: ['openrouter', 'openrouter_chain']);
```

**`LLMGateway` e completamente eliminado.** O SDK gerencia providers, autenticacao e failover.

### 3.4. RAG Vetorial: ChromaDB → pgvector nativo

**Antes (ChromaDB/SQLite-Vec):**
```php
// Gerar embedding via Ollama
$embedding = $this->ollama->embed($prdText);
// Buscar no ChromaDB
$similar = $this->chromadb->query($embedding, limit: 3);
```

**Depois (pgvector + Laravel 13):**
```php
// Gerar embedding nativo
$embedding = Str::of($task->prd_payload)->toEmbeddings();

// Buscar diretamente no PostgreSQL
$solutions = DB::table('problems_solutions')
    ->whereVectorSimilarTo('embedding', $task->prd_payload)
    ->where('project_id', $task->project_id)
    ->limit(3)
    ->get();

// Ou no Eloquent:
$solutions = ProblemSolution::query()
    ->whereVectorSimilarTo('embedding', $task->prd_payload)
    ->where('project_id', $task->project_id)
    ->limit(3)
    ->get();
```

**Eliminamos:**
- ChromaDB (servico Python separado)
- SQLite-Vec (extensao separada)
- Integracao custom de embeddings
- Toda a infraestrutura de banco vetorial separado

**pgvector no PostgreSQL faz tudo nativamente**, e o Laravel 13 da a interface fluent.

### 3.5. Conversacao Persistente: session_history → RemembersConversations

**Antes (custom):**
```php
// Salvar manualmente em session_history
SessionHistory::create([
    'project_id' => $project->id,
    'agent_id' => $agent->id,
    'compressed_context' => $this->compress($messages),
]);
```

**Depois (SDK nativo):**
```php
class BackendSpecialist implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    // ...
}

// Iniciar conversa
$response = BackendSpecialist::make()
    ->forUser($user)
    ->prompt('Crie o Model Post');
$conversationId = $response->conversationId;

// Continuar conversa (contexto automatico)
$response = BackendSpecialist::make()
    ->continue($conversationId, as: $user)
    ->prompt('Agora adicione soft deletes');
```

O SDK gerencia `agent_conversations` e `agent_conversation_messages` automaticamente.

### 3.6. Multi-Agent: O Padrão Customizado de Orquestração State-Driven

O Laravel 13 documenta oficialmente **5 padrões de agentes**, e o AI-Dev adota alguns de forma nativa e diverge de outros **propositalmente por questões de resiliência e recuperação de falhas**.

| Padrão Laravel 13 | Uso no AI-Dev |
|---|---|
| **Prompt Chaining** | Task → Orchestrator → Subagente → QA (pipeline sequencial) |
| **Routing** | Classificador rápido (usando `#[UseCheapestModel]` para economizar tokens) roteia para especialista correto |
| **Evaluator-Optimizer** | QA Auditor avalia → Subagente corrige → QA re-avalia (loop via jobs) |
| **Parallelization (Divergência)** | Em vez de rodar subtasks em memória via `Concurrency::run()` (que perde estado se o servidor reiniciar), o AI-Dev despacha jobs paralelos na fila do Horizon. |
| **Orchestrator-Workers (Divergência)** | Em vez de registrar subagentes como Tools (Agent-as-a-Tool), o Orchestrator escreve um plano estruturado no banco e encerra. O sistema reage aos registros no banco para chamar workers via filas. |

**Por que a divergência (State-Driven Customizada)?**
Se o servidor reiniciar ou um worker falhar (ex: rate limit), **nada está perdido**. O estado da orquestração está salvo no banco de dados (`subtasks`), e o AI-Dev sabe exatamente de onde recomeçar. Os padrões nativos síncronos (`Concurrency::run` ou chamadas recursivas `Agent-as-a-Tool`) perderiam todo o progresso em caso de interrupção do processo pai.

**Exemplo: Pipeline com Orquestração Baseada em Banco de Dados:**
```php
use Illuminate\Support\Facades\Queue;

// Fase 1: Orchestrator planeja e retorna o formato estruturado
$plan = OrchestratorAgent::make(task: $task)
    ->prompt($task->prd_payload, provider: 'openrouter', model: 'anthropic/claude-opus-4.7');

// Salvamos o estado no banco de dados para garantir a resiliência
foreach ($plan['subtasks'] as $subtaskData) {
    $subtask = Subtask::create($subtaskData);
    
    // Fase 2: Paralelismo resiliente despachando para as Filas
    if (empty($subtaskData['dependencies'])) {
        Queue::push(new ProcessSubtaskJob($subtask));
    }
}
// O OrchestratorAgent termina aqui. O Horizon assume a execução dos ProcessSubtaskJob.
```

### 3.7. Queue Routing: Disperso → Centralizado

**Antes (em cada Job):**
```php
class OrchestratorJob implements ShouldQueue
{
    public $queue = 'orchestrator';
    public $connection = 'redis';
    public $tries = 3;
    public $timeout = 300;
}
```

**Depois (centralizado + attributes):**
```php
// AppServiceProvider
Queue::route(OrchestratorAgent::class, connection: 'redis', queue: 'orchestrator');
Queue::route(QAAuditorAgent::class, connection: 'redis', queue: 'qa');
Queue::route(SubagentJob::class, connection: 'redis', queue: 'agents');

// Ou via PHP Attributes:
#[Tries(3)]
#[Timeout(300)]
#[Backoff(30, 60, 120)]
class OrchestratorAgent implements Agent { ... }
```

---

## 4. Tabelas: O que Muda no Schema

### 4.1. Tabelas que o SDK Cria Automaticamente

| Tabela | Criada pelo SDK | Substitui |
|---|---|---|
| `agent_conversations` | Sim (migration do SDK) | `session_history` (parcialmente) |
| `agent_conversation_messages` | Sim (migration do SDK) | Historico de mensagens custom |

### 4.2. Tabelas que Mantemos (com ajustes)

| Tabela | Status | Ajuste |
|---|---|---|
| `projects` | **Manter** | Substituir `gemini_session_id`/`claude_session_id` por uma única coluna `anthropic_session_id` (SDK gerencia conversas via OpenRouter → família Anthropic) |
| `tasks` | **Manter** | Sem mudanca |
| `subtasks` | **Manter** | Sem mudanca |
| `agents_config` | **Manter** | Adicionar referencia ao Agent class. O `role_description` vira o `instructions()` do Agent |
| `task_transitions` | **Manter** | Sem mudanca |
| `problems_solutions` | **Manter** | Adicionar coluna `vector` (tipo `vector(1536)`) para pgvector |
| `agent_executions` | **Manter** | Complementar com eventos do SDK |
| `tool_calls_log` | **Manter** | Complementar com middleware do SDK |
| `context_library` | **Manter** | Sem mudanca |
| `webhooks_config` | **Manter** | Sem mudanca |

### 4.3. Tabelas que Eliminamos

| Tabela | Motivo |
|---|---|
| `session_history` | Substituida por `agent_conversations` + `agent_conversation_messages` do SDK |

### 4.4. Migration para `problems_solutions` com pgvector

```php
Schema::table('problems_solutions', function (Blueprint $table) {
    $table->vector('embedding', 1536)->nullable();
    // Indice para busca vetorial rapida
    $table->index('embedding', 'problems_solutions_embedding_idx')
          ->algorithm('ivfflat')
          ->with(['lists' => 100]);
});
```

---

## 5. Estrutura de Arquivos: Nova Organizacao

### 5.1. Antes (Laravel 12)

```
app/
  Tools/
    ShellTool.php          ← ToolInterface custom
    FileTool.php
    GitTool.php
    DatabaseTool.php
    SearchTool.php
    TestTool.php
    SecurityTool.php
    DocsTool.php
    MetaTool.php
  Services/
    LLMGateway.php         ← Gateway multi-provider custom
    PromptFactory.php      ← Montagem de prompts custom
    ToolRouter.php         ← Roteamento de tool calls custom
    ContextManager.php     ← Gestao de contexto custom
    FileLockManager.php
    PRDValidator.php
  Jobs/
    OrchestratorJob.php    ← Job custom
    SubagentJob.php        ← Job custom
    QAAuditJob.php         ← Job custom
    ContextCompressionJob.php
```

### 5.2. Depois (Laravel 13)

```
app/
  Ai/
    Agents/
      OrchestratorAgent.php     ← implements Agent, HasStructuredOutput, HasTools
      QAAuditorAgent.php        ← implements Agent, HasStructuredOutput
      BackendSpecialist.php     ← implements Agent, Conversational, HasTools
      FrontendSpecialist.php    ← implements Agent, Conversational, HasTools
      FilamentSpecialist.php    ← implements Agent, Conversational, HasTools
      DatabaseSpecialist.php    ← implements Agent, Conversational, HasTools
      DevOpsSpecialist.php      ← implements Agent, Conversational, HasTools
      SecuritySpecialist.php    ← implements Agent, HasTools
      PerformanceAnalyst.php    ← implements Agent, HasStructuredOutput
      ContextCompressor.php     ← implements Agent (usa Ollama)
    Tools/
      ShellTool.php             ← implements Laravel\Ai\Contracts\Tool
      FileTool.php
      GitTool.php
      DatabaseTool.php
      SearchTool.php
      TestTool.php
      SecurityTool.php
      DocsTool.php
      MetaTool.php
      SimilaritySearchTool.php  ← NOVO: busca vetorial via pgvector
  Services/
    PromptFactory.php           ← SIMPLIFICADO: apenas monta contexto dinamico
    FileLockManager.php         ← MANTER: logica custom de file locking
    PRDValidator.php            ← SIMPLIFICAR: usar JsonSchema do SDK
    TaskOrchestrator.php        ← NOVO: coordena o pipeline Agent→QA→Git
  Jobs/
    ProcessTaskJob.php          ← SIMPLIFICADO: orquestra o pipeline
    ContextCompressionJob.php   ← SIMPLIFICADO: usa Ai::embed() para embeddings
```

**Eliminados:**
- `LLMGateway.php` → `config/ai.php` + `Lab` enum
- `ToolRouter.php` → SDK roteia automaticamente
- `ContextManager.php` → `RemembersConversations` trait
- `OrchestratorJob.php` → `OrchestratorAgent` class
- `SubagentJob.php` → Agent classes especializadas
- `QAAuditJob.php` → `QAAuditorAgent` class

---

## 6. config/ai.php - Configuracao Central

```php
return [
    'default' => env('AI_PROVIDER', 'openrouter'),

    'providers' => [
        'openrouter' => [
            'driver' => 'openai',  // OpenAI-compatible API
            'key' => env('OPENROUTER_API_KEY'),
            'url' => 'https://openrouter.ai/api/v1',
        ],
        'ollama' => [
            'driver' => 'ollama',
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],
    ],

    // Mapeamento Agente → Provider/Modelo (equivale ao LLMGateway)
    'agent_routing' => [
        'orchestrator'   => ['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-4.7'],
        'qa_auditor'     => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'security'       => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'backend'        => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'frontend'       => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'filament'       => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'database'       => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'devops'         => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4-6'],
        'docs'           => ['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4-5-20251001'],
        'compressor'     => ['provider' => 'ollama',     'model' => 'qwen2.5:0.5b'],
    ],
];
```

---

## 7. Plano de Migracao (Passo a Passo)

### Fase 0: Preparacao (sem quebrar nada)
1. Upgrade Laravel 12 → 13 (`composer update`)
2. Instalar AI SDK (`composer require laravel/ai`)
3. Publicar config (`php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`)
4. Rodar migration do SDK (`php artisan migrate`) → cria `agent_conversations` e `agent_conversation_messages`
5. Configurar `config/ai.php` com os providers existentes
6. Verificar que tudo continua funcionando (zero breaking changes prometido)

### Fase 1: Migrar Tools (menor risco)
1. Criar `app/Ai/Tools/` e migrar cada Tool para o `Tool` contract do SDK
2. Manter as Tools antigas funcionando em paralelo
3. Testar cada Tool isoladamente
4. Remover `ToolInterface` e `ToolRouter` quando todas estiverem migradas

### Fase 2: Migrar Agentes (maior impacto)
1. Criar `app/Ai/Agents/OrchestratorAgent.php` usando `Agent` contract
2. Criar `app/Ai/Agents/QAAuditorAgent.php`
3. Criar Agent classes para cada especialista
4. Criar `TaskOrchestrator.php` que coordena o pipeline usando padroes nativos
5. Testar o ciclo completo: Task → Orchestrator → Specialist → QA → Git
6. Remover Jobs antigos quando agents estiverem funcionando

### Fase 3: Migrar Infraestrutura (limpeza)
1. Migrar RAG para pgvector nativo (adicionar coluna `vector` em `problems_solutions`)
2. Migrar conversas para `RemembersConversations`
3. Eliminar `LLMGateway.php` (usar config/ai.php)
4. Simplificar `PromptFactory` (manter apenas logica de cache blocks)
5. Eliminar `ContextManager.php`
6. Configurar `Queue::route()` centralizado

### Fase 4: Limpeza Final
1. Remover codigo morto (Tools antigas, Jobs antigos, Gateway)
2. Atualizar seeders do `agents_config` com referencias aos Agent classes
3. Atualizar Filament Resources para refletir nova estrutura
4. Rodar suite de testes completa
5. Atualizar documentacao final

---

## 8. Riscos e Mitigacoes

| Risco | Probabilidade | Mitigacao |
|---|---|---|
| AI SDK ainda imaturo (v1.0) | Media | Manter fallback para implementacao custom em caso de bugs |
| OpenRouter como ponto único de falha externa | Media | Falhas de modelo individual fazem failover dentro da família Anthropic (Opus → Sonnet). Falha total do OpenRouter escala para humano |
| RemembersConversations pode nao ser flexivel o suficiente | Media | Implementar `Conversational` manualmente se necessario |
| pgvector performance com muitos embeddings | Baixa | Usar indice IVFFlat, ja temos pgvector 0.6 |
| Structured output pode nao funcionar para todos os modelos via OpenRouter | Media | Testar antes de migrar; fallback para output livre + parser manual se necessário |

---

## 9. Beneficios Esperados

| Metrica | Antes | Depois | Ganho |
|---|---|---|---|
| Linhas de codigo custom para infra AI | ~3000 (estimado) | ~800 | **-73%** |
| Dependencias externas (ChromaDB, etc) | 3-4 servicos | 0 (so pgvector) | **-100%** |
| Tempo para adicionar novo provider | ~1 dia (novo driver) | ~5 min (config/ai.php) | **-99%** |
| Tempo para criar novo agente | ~2h (Job + prompt + routing) | ~15 min (Agent class) | **-87%** |
| Cobertura de testes de AI | Manual | `AgentFake` nativo | **Automatizado** |
| Patterns multi-agent | Custom (fragil) | Documentados oficialmente | **Padronizado** |
| Busca vetorial | Servico separado | PostgreSQL nativo | **Simplificado** |

---

## 10. Conclusao

O Laravel 13 transforma o AI-Dev de um "sistema que implementa tudo do zero" para um "sistema que orquestra ferramentas nativas do framework". A maioria da infraestrutura que planejamos construir nas Fases 1-3 ja existe como first-party no Laravel 13.

**O foco do AI-Dev agora muda de "construir a infraestrutura de AI" para "construir a logica de negocio especifica":**
- Orquestracao deterministica de tasks com maquina de estados
- Auditoria de qualidade com criterios TALL estritos
- Self-healing via Sentinel
- Git branching automatico por task
- Dashboard Filament para observabilidade

O framework cuida do resto.
