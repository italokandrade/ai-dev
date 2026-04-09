# Arquitetura do AI-Dev (AndradeItalo.ai)

## 1. Visão Geral da Arquitetura
O AI-Dev é um ecossistema de desenvolvimento de software autônomo, assíncrono e multi-agente, estritamente focado na stack TALL (Tailwind, Alpine.js, Laravel, Livewire) + Filament v5 + Anime.js. Ele opera em background (headless), guiado por um banco de dados relacional e enriquecido por uma memória de longo prazo vetorial.

## 2. Modelagem do Banco de Dados Relacional (Core)
O sistema não possui UI própria; ele é "orientado a dados". O Orquestrador faz *polling* ou reage a *webhooks/events* nestas tabelas.

### Tabelas Principais (Esquema Simplificado)

**`projects`**
- `id` (UUID/PK)
- `name` (String)
- `github_repo` (String - ex: `git@github.com:italokandrade/erp-sys.git`)
- `local_path` (String - caminho no servidor dev)
- `tech_stack_overrides` (JSON - configurações específicas do projeto se divergir do padrão)
- `status` (Enum: active, archived)

**`tasks`**
- `id` (UUID/PK)
- `project_id` (FK -> projects)
- `title` (String)
- `description` (Text - O briefing ou o log de erro do CI)
- `status` (Enum: pending, in_progress, review, testing, completed, failed)
- `priority` (Int)
- `assigned_agent_id` (FK -> agents - Opcional, o Orquestrador define)
- `created_at`, `updated_at`

**`subtasks`** (A quebra feita pelo Orquestrador)
- `id` (UUID/PK)
- `task_id` (FK -> tasks)
- `description` (Text)
- `status` (Enum: pending, running, success, error)
- `assigned_agent` (String - ex: 'backend-specialist')
- `dependencies` (JSON - IDs de subtarefas que precisam terminar antes desta)
- `result_log` (Text - Saída da execução)

**`agents_config`** (Agnosticismo Dinâmico de LLMs)
- `id` (String/PK - ex: 'orchestrator', 'filament-v5-specialist')
- `role_description` (Text - System Prompt base)
- `provider` (String - ex: 'anthropic', 'openai', 'ollama')
- `model` (String - ex: 'claude-3-opus-20240229', 'llama3')
- `api_key_env_var` (String - Nome da variável de ambiente com a chave)
- `temperature` (Float)
- `max_tokens` (Int)

**`context_library`** (Padrões Estritos - Alternativa ao BD Vetorial para padrões fixos)
- `id` (UUID/PK)
- `category` (Enum: filament_resource, livewire_component, animejs_animation, etc.)
- `content` (Text - Código de exemplo perfeito)
- `description` (Text - Quando usar)

## 3. Fluxo Lógico do Orquestrador (O Cérebro)

O fluxo abaixo representa o ciclo de vida de uma `Task` desde sua captação até o commit.

```text
LOOP CONTÍNUO (Daemon/Worker):
1. [BUSCA] Ler tabela `tasks` onde status = 'pending' ORDER BY priority DESC LIMIT 1.
2. [LOCK] Mudar status da task para 'in_progress'.

3. [MEMÓRIA & CONTEXTO]
   a. Consultar BD Vetorial (ex: Pinecone/ChromaDB) usando a `description` da task:
      -> "Existem resoluções de bugs parecidos no passado?"
   b. Consultar `context_library` com base em palavras-chave (ex: "Filament", "Table"):
      -> Carregar os "Padrões Estritos TALL".
   c. Compilar o [Contexto Global] = (Histórico Vetorial + Padrões Estritos + Briefing da Task).

4. [PLANEJAMENTO] (Chamada LLM Pesada - ex: Claude 3.5 Sonnet / Opus)
   -> Enviar [Contexto Global] para o Agente 'orchestrator'.
   -> O Orquestrador devolve um JSON com o plano de ação (Subtasks e Dependências).
   -> Inserir Subtasks na tabela `subtasks`.

5. [EXECUÇÃO PARALELA] (Multi-Agent Dispatch)
   Para cada Subtask na fila (respeitando dependências):
     a. Carregar a configuração do Agente a partir de `agents_config` (ex: Modelo, Provider).
     b. Montar o Prompt do Agente: 
        (System Prompt do Agente) + (Padrões de Código da Library) + (Descrição da Subtask).
     c. Despachar a execução (Assíncrona). 
        *Nota: Agentes podem usar Tools (Read File, Write File, Run Tests) via MCP.*
     d. Aguardar conclusão. Se falhar, tentar auto-correção X vezes.
   
6. [VALIDAÇÃO E REVIEW]
   -> Agente 'reviewer' analisa o diff gerado nas subtarefas contra os padrões TALL.
   -> Se OK, avançar. Se não OK, gerar nova subtarefa de correção.

7. [CI/CD & COMMIT]
   -> Orquestrador comanda o Git local: `git add .`, `git commit -m "feat/fix: [Task Title]"`, `git push`.
   -> Mudar status da task para 'testing' (Aguardando CI).

8. [FEEDBACK LOOP (Webhook do CI)]
   -> O Servidor de Testes roda `php artisan test` e `php artisan dusk`.
   -> Se ERRO: O CI insere uma NOVA Task na tabela `tasks` com o log do erro e status 'pending', referenciando a task original.
   -> Se SUCESSO: Mudar status da task para 'completed'. Atualizar BD Vetorial com a solução validada.
```

## 4. Memória Persistente de Longo Prazo (Arquitetura Híbrida)

A gestão da memória é dividida para evitar o esgotamento de tokens:

*   **Janela Deslizante com Sumarização (Short-term):** O Orquestrador mantém um buffer das últimas *N* interações no projeto atual. Quando atinge 80% do limite de tokens do modelo, um subagente de "Sumarização" comprime os eventos mais antigos em parágrafos densos de contexto, descartando o ruído (logs detalhados, tentativas falhas menores) e mantendo apenas as decisões arquiteturais da sessão.
*   **RAG Vetorial (Long-term):**
    *   **O que salvar:** Sempre que uma `Task` é completada com sucesso, o diff do código, o problema original e a explicação de como foi resolvido são vetorizados (ex: via OpenAI Embeddings ou modelos open-source como `all-MiniLM-L6-v2`) e salvos no banco vetorial.
    *   **Como usar:** No passo 3 do fluxo do Orquestrador, uma busca semântica traz o contexto de como problemas semelhantes foram resolvidos *nesta base de código específica*, evitando alucinações.

## 5. Gerenciamento de Injeção de Padrões (Few-Shot) e Multi-LLMs

A chave para manter o padrão sem sobrecarregar modelos menores é a **Injeção Dinâmica por Roteamento**:

1.  **A Fábrica de Prompts (Prompt Factory):** Um serviço centralizado que constrói o payload para o LLM. Ele recebe: `Agent ID`, `Task Description` e `Project Path`.
2.  **Injeção Cirúrgica (RAG de Padrões):** Em vez de injetar toda a base de conhecimento TALL em cada prompt, usamos *tags* ou busca semântica na `context_library`.
    *   *Exemplo:* Se a subtarefa do agente foca em `App\Filament\Resources`, o Prompt Factory injeta apenas o few-shot referente a "Padrão Filament V5 da AndradeItalo.ai" no início do System Prompt.
3.  **Agnosticismo via Interface Unificada:** O sistema não usa SDKs específicos de cada LLM espalhados pelo código. Usamos um padrão (como LiteLLM em Python ou as APIs abstraídas do Vercel AI SDK em Node) onde o `agents_config` dita a rota.
    *   O Orquestrador pede `agentModels["frontend-specialist"]`.
    *   O banco retorna: `provider: ollama, model: qwen2.5-coder`.
    *   O Request viaja para o Ollama local, injetado com 2 exemplos cruciais de Alpine.js + Anime.js, mantendo a latência baixa e o foco cirúrgico.
