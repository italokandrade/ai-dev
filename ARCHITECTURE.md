# Arquitetura do AI-Dev (AndradeItalo.ai)

## 1. Visão Geral da Arquitetura
O AI-Dev é um ecossistema de desenvolvimento de software autônomo, assíncrono e multi-agente, estritamente focado na stack TALL (Tailwind, Alpine.js, Laravel, Livewire) + Filament v5 + Anime.js. Ele opera em background (headless), guiado por um banco de dados relacional e enriquecido por uma memória de longo prazo vetorial. As instruções trafegam em formato PRD (Product Requirement Document) para garantir clareza absoluta na comunicação entre os agentes.

## 2. Modelagem do Banco de Dados Relacional (Core), Web UI e API Headless
Diferente da versão inicial puramente CLI, o AI-Dev contará com uma **Interface Web (UI)** desenvolvida em Filament v5 e uma **API Headless** (via gRPC ou REST). 
- **Web UI:** Servirá *exclusivamente* para gestão: cadastrar novos projetos, configurar o prompt dos agentes, e inserir tarefas/PRDs manualmente. 
- **API Headless:** Permitirá que sistemas externos (como webhooks do GitHub, pipelines de CI/CD ou extensões de VS Code) injetem tarefas e ouçam o progresso em tempo real.
O Orquestrador continua operando em background via *polling/events* nestas tabelas.

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
- `prd_payload` (Text - O PRD principal contendo o briefing detalhado, regras e critérios de aceite)
- `status` (Enum: pending, in_progress, qa_audit, testing, completed, failed)
- `priority` (Int)
- `assigned_agent_id` (FK -> agents - Opcional, o Orquestrador define)
- `created_at`, `updated_at`

**`subtasks`** (A quebra feita pelo Orquestrador)
- `id` (UUID/PK)
- `task_id` (FK -> tasks)
- `sub_prd_payload` (Text - Mini-PRD focado apenas na responsabilidade do subagente executor)
- `status` (Enum: pending, running, qa_audit, success, error)
- `assigned_agent` (String - ex: 'backend-specialist')
- `dependencies` (JSON - IDs de subtarefas que precisam terminar antes desta)
- `result_log` (Text - Saída da execução)

**`agents_config`** (Agnosticismo Dinâmico de LLMs)
- `id` (String/PK - ex: 'orchestrator', 'qa_auditor', 'filament-v5-specialist')
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

## 3. Automação Agêntica Robusta: Fluxo Lógico e Auditoria (O Cérebro e o Juiz)

Para garantir que a automação não se torne um "prompt chain" livre e alucinado, o AI-Dev adota **Orquestração Determinística (State-Driven)**. O fluxo é rigidamente guiado pela máquina de estados do MariaDB, impedindo loops infinitos. 

Além disso, adotamos a classificação oficial de **Padrões de Agentes Claros**:
1. **`ORCHESTRATOR` (Planner)**: O planejador central estático. Recebe o PRD principal e o quebra em Sub-PRDs focados.
2. **`QA_AUDITOR` (Validator/Judge)**: O juiz implacável. Audita toda saída gerada comparando-a estritamente contra o PRD fornecido.
3. **`SUBAGENTES` (Executors)**: Os especialistas dinâmicos (Backend, Frontend, etc.) focados apenas em agir.

**Contratos Estritos para Ferramentas (Tool Layer/MCP):**
Todas as ações que interagem com o sistema (ler arquivo, executar comando) são feitas por meio de *Tools* com schemas JSON rigorosamente validados, eliminando falhas por chamadas de parâmetros inexistentes.

### Ciclo de Vida da `Task` (Design Fail-Safe e Action-Driven)

O AI-Dev abandona o "Heartbeat Temporal" (loops a cada X minutos que gastam tokens lendo a mesma coisa sem agir). O sistema adota o **Action-Driven Heartbeat**: o ciclo de contexto e planejamento só avança após ações concretas (ex: a cada N tool calls) ou eventos reais via Webhooks, evitando requisições vazias.

```text
EVENTO GATILHO (Webhook/Nova Tarefa):
1. [BUSCA] Ler tabela `tasks` onde status = 'pending' ORDER BY priority DESC LIMIT 1.
2. [LOCK] Mudar status da task para 'in_progress'.

3. [MEMÓRIA & CONTEXTO]
   a. Consultar BD Vetorial usando o `prd_payload` da task.
   b. Consultar `context_library` para carregar os "Padrões Estritos TALL".
   c. Compilar o [Contexto Global].

4. [PLANEJAMENTO VIA PRD] (Planner: 'ORCHESTRATOR')
   -> Enviar [Contexto Global] + [PRD Principal].
   -> Divide o PRD Principal em múltiplos [Sub-PRDs], um para cada especialista dinâmico.
   -> Inserir Subtasks na tabela `subtasks` contendo os Sub-PRDs.

5. [EXECUÇÃO PARALELA DOS SUBAGENTES] (Executors)
   Para cada Subtask na fila (respeitando dependências):
     a. Montar o Prompt: (System Prompt) + (Padrões de Código) + (Sub-PRD).
     b. Despachar execução através de Contratos Estritos (Ferramentas fortemente tipadas).

6. [AUDITORIA LOCAL] (Judge: 'QA_AUDITOR')
   -> O QA_AUDITOR recebe o [Sub-PRD] original e o [Resultado Final do Subagente].
   -> "O código atende estritamente a TODOS os critérios do Sub-PRD sem ferir padrões?"
   -> Se FALHAR: Rejeita a entrega com feedback detalhado. (Limite de X retentativas).
   -> Se ESTOURAR RETENTATIVAS: O design **Fail-Safe** engatilha. A tarefa para e escala para **Human-in-the-Loop** na interface Web do Filament.
   -> Se PASSAR: Muda a subtask para 'success'.

7. [INTEGRAÇÃO E AUDITORIA GLOBAL] 
   -> O Agente Especialista Líder consolida as peças de código no projeto.
   -> O QA_AUDITOR faz a checagem final macro contra o [PRD Principal].
   -> Se PASSAR: Avança para CI/CD.

8. [CI/CD & COMMIT]
   -> Orquestrador comanda o Git local: `git add .`, `git commit -m "feat: [Task Title]"`, `git push`.
   -> Mudar status da task para 'testing'.

9. [FEEDBACK LOOP & SELF-HEALING (Auto-Correção Nativa)]
   O sistema possui duas camadas de feedback implacáveis:
   -> **CI/CD Testing:** O Servidor de Testes roda testes (Dusk/Pest). Se falhar, insere NOVA Task com log do erro.
   -> **O Sentinela (Runtime Self-Healing):** Todo projeto gerado pelo AI-Dev terá um "Sentinela" embutido (um Exception Handler customizado no `bootstrap/app.php`). Em vez de depender de pacotes visuais para humanos (como `spatie/laravel-error-solutions`), o Sentinela intercepta silenciosamente qualquer *Exception* (Fatais, Syntax Errors, Query Exceptions) gerada pela IA na aplicação destino. Assim que a falha é detectada, o Sentinela injeta automaticamente uma Task de Prioridade Máxima na tabela `tasks` do Orquestrador, contendo o Stack Trace completo e a linha exata do arquivo. O ciclo reinicia sozinho e os agentes corrigem o próprio código quebrado imediatamente antes do próximo commit.
   -> Se SUCESSO na execução: Salva o (PRD + Solução Validada) no Banco Vetorial. Status 'completed'.
```

## 4. Memória Persistente, Prompt Caching e Economia de Contexto

Em vez de salvar o histórico em um arquivo de texto (`memory.md`) que cresce eternamente e devora tokens (como visto em outros sistemas), o AI-Dev adota **Gestão de Contexto via Banco de Dados Relacional (MariaDB/SQLite)**. Isso permite buscar dados antigos sem embutir o histórico inteiro no *prompt*.

A gestão de contexto é focada em altíssima economia (inspirada no *Hermes Agent*):

*   **Compressão Ativa de Contexto (Short-term) via Modelo Local:** O Orquestrador e os Subagentes possuem uma **trava de compressão (threshold de 0.6)**. Quando a sessão atinge 60% do limite da janela de contexto, o sistema faz um reset forçado na sessão. A compressão (geração de um resumo denso do histórico) será feita **em segundo plano por um modelo local extremamente leve (ex: Qwen2.5:0.5b ou Llama3.2:1b rodando via Ollama)**. Isso garante a manutenção do "contexto infinito" da conversa e não prejudica as IAs na compreensão recente, além de poupar os preciosos tokens dos modelos maiores (Gemini/Claude) e usar o mínimo de recursos do servidor.
*   **Prompt Caching Nativo:** Para provedores que suportam (como Anthropic Claude 3.5 via OpenRouter ou Gemini), o sistema estrutura o *System Prompt* (Padrões TALL + Docs) de forma estática no topo da requisição. Isso aciona o cache de prompt na API da LLM, derrubando o custo e o tempo de leitura do contexto repetido em até 90%.
*   **RAG Vetorial (Long-term):**
    *   **O que salvar:** Sempre que uma `Task` finaliza com sucesso, o PRD original e o *diff* do código vencedor são vetorizados e salvos no banco.
    *   **Como usar:** No passo 3 do fluxo, uma busca semântica traz o contexto de como problemas/PRDs semelhantes foram resolvidos *nesta base de código específica*.

## 5. Gerenciamento de Injeção de Padrões (Few-Shot) e Multi-LLMs

A chave para manter o padrão sem sobrecarregar modelos menores é a **Injeção Dinâmica por Roteamento**:

1.  **A Fábrica de Prompts (Prompt Factory):** Um serviço centralizado que constrói o payload para o LLM. Ele recebe: `Agent ID`, `PRD / Sub-PRD` e `Project Path`.
2.  **Injeção Cirúrgica (RAG de Padrões):** Em vez de injetar toda a base de conhecimento em cada prompt, usamos busca semântica na `context_library`.
    *   *Exemplo:* Se o Sub-PRD foca em `App\Filament\Resources`, o Prompt Factory injeta apenas o few-shot referente ao "Padrão Filament V5".
3.  **Agnosticismo via Interface Unificada:** O `agents_config` dita a rota para cada LLM, e **a definição de qual modelo cada agente usa é configurada diretamente na Web UI**.
    *   Para garantir escalabilidade, altíssima velocidade e custo zero em inferência bruta, os Agentes Dinâmicos (Executores de Código) utilizarão **exclusivamente a ponte do Proxy Gemini** já funcional no servidor (`gemini_watchdog.sh`), usufruindo da camada gratuita de modelos como o `Gemini 3.1 Flash`.
    *   O `QA_AUDITOR` ou o `ORCHESTRATOR`, que exigem raciocínio crítico de planejamento, poderão ser roteados via **OpenRouter** para acessar modelos variados e potentes (ex: Claude 3.5 Sonnet, OpenAI o1, etc.), dependendo da complexidade da tarefa. Tudo isso sendo facilmente ajustável pelo cadastro de agentes no sistema web.

## 6. Arsenal de Ferramentas (The Tool Layer) e MCP Isolado

Inspirado no OpenClaw, **o AI-Dev não embutirá ferramentas pesadas no código-fonte principal (Core)**. Todas as ferramentas atuarão como plugins independentes, comunicando-se com o Orquestrador através do *Model Context Protocol (MCP)*. Isso impede que vulnerabilidades nas *tools* afetem a segurança do núcleo Laravel.

As ferramentas essenciais, sem redundância de função, incluem:

1. **`TerminalExecutorTool` (Inspirado no BashTool / Terminal):**
   * *Função:* Executar comandos de terminal isolados no servidor com timeouts e restrições.
   * *Uso Prático:* Rodar `php artisan make:filament-resource`, `npm run build`, e `git status`.
2. **`FileSurgeryTool` (Inspirado no FileEdit / Diffs):**
   * *Função:* Manipulação cirúrgica de arquivos (Patch/Diffs ou Search & Replace).
   * *Uso Prático:* Alterar apenas um método num Controller sem tocar e sobrecarregar o arquivo inteiro.
3. **`CodeInspectorTool` (Inspirado no GlobTool / GrepTool / LSPTool):**
   * *Função:* Varredura de código (Glob/Grep) e análise estática AST.
   * *Uso Prático:* Buscar onde classes ou variáveis são usadas em todo o projeto em segundos, sem "adivinhações".
4. **`FileSystemNavigatorTool` (Inspirado no ListDirectory / EnterWorktree):**
   * *Função:* Navegação estrutural de diretórios.
   * *Uso Prático:* Listar a árvore do projeto (ex: pasta `/app/Http/Controllers`) para mapear arquitetura.
5. **`DuckDuckGoSearchTool` (O Roteador Rápido):**
   * *Função:* Pesquisa ampla na web (fallback gratuito).
   * *Uso Prático:* A IA precisa apenas do *link* ou de uma *dica superficial* no Google/StackOverflow. Como não exige carregamento de DOM, é disparado primeiro para descobrir "onde" a informação está.
6. **`FirecrawlScraperTool` (O Extrator Limpo Self-Hosted):**
   * *Função:* Raspagem inteligente de páginas da web transformando HTML em puro Markdown limpo.
   * *Uso Prático:* Em vez de gastarmos com a API paga do Firecrawl ou de usar um agente visual ineficiente que clica na tela (desperdiçando tokens e recursos), **hospedaremos o próprio motor do Firecrawl localmente, de forma nativa no servidor (sem Docker)**. O motor puxará a estrutura de dados enxuta da documentação na web e a devolverá mastigada em Markdown para a LLM ler, garantindo total privacidade e controle.
7. **`GitHubIntegrationTool` (Refatoração Inteligente):**
   * *Função:* Acesso nativo à API do GitHub (Diffs, Commits, Pull Requests).
   * *Uso Prático:* Permite ler Diffs históricos para entender o contexto de uma feature sem gastar a cota de leitura de arquivos brutos. A IA enxerga o código da mesma forma que um humano revisando um Pull Request.
8. **`MarkdownDocsTool` (Inspirado no NotebookEditTool / Markdown):**
   * *Função:* Parsear, criar e atualizar documentações técnicas (.md).
   * *Uso Prático:* Atualizar o `README.md` após implementar a feature.
9. **`TaskTrackerTool` (Inspirado no TaskCreate / TodoWrite):**
   * *Função:* Gestão de memória de curto prazo por anotações (TODOs) dinâmicos.
   * *Uso Prático:* O Agente anota tarefas dependentes ("Falta a View X") para não quebrar a lógica enquanto foca no Controller Y.
10. **`SchemaExplorerTool` (Nativo AI-Dev):**
   * *Função:* Inspeção segura de banco de dados (`DESCRIBE`, ler migrations ativas).
   * *Uso Prático:* Garantir que o *Database Specialist* conheça a tabela exata antes de criar `Alter/Create Queries`.

## 7. Referências e Abstração de Conhecimento (Third-World Evolution)

Para acelerar o desenvolvimento e garantir que o AI-Dev (AndradeItalo.ai) opere no estado da arte, abstrairemos conceitos, lógicas de paralelismo e ferramentas dos seguintes repositórios de código aberto:

*   **OpenClaude (`https://github.com/Gitlawb/openclaude`)**:
    *   *Foco da Extração:* Como gerir de forma eficiente a injeção do Model Context Protocol (MCP) para uso de ferramentas do sistema (Ler/Escrever Arquivos, Rodar Comandos) pelo LLM.
    *   *Foco da Extração:* A lógica abstrata de "routing" no JSON de configuração para selecionar diferentes provedores (Anthropic, OpenAI) dinamicamente.
*   **OpenClaw (`https://github.com/openclaw/openclaw`)**:
    *   *Foco da Extração:* A arquitetura subjacente de delegação multi-agente assíncrona.
    *   *Foco da Extração:* Lógicas de gerenciamento do ciclo de vida das *Tasks* em sistemas headless (daemon/workers) orientados a banco de dados.
*   **Hermes Agent (`https://github.com/NousResearch/hermes-agent`)**:
    *   *Foco da Extração:* O conceito de Action-Driven Heartbeat (abandono do timer vazio) e a preferência pelo uso de Bancos de Dados SQLite/Relacionais para memória com **Compressão Ativa** em vez de arquivos Markdown infinitos. 
    *   *Foco da Extração:* Filosofia inteligente de web scraping usando APis dedicadas (como o Firecrawl) para retornar puro Markdown em vez de sobrecarregar a LLM com ações visuais pesadas no DOM.

**A Missão do Terceiro Mundo (The Best of Both Worlds):** O AI-Dev não é um fork direto. Ele atua como uma evolução que pega as ideias dispersas de CLI/Local de ambos os repositórios, mescla isso com a rigidez do controle via Tabela de Banco de Dados Relacional, e padroniza tudo *exclusivamente* para o ecossistema TALL + Filament + Anime.js, elevando a abstração ao máximo.
