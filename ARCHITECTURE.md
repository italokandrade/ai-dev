# Arquitetura do AI-Dev (AndradeItalo.ai)

## 1. Visão Geral da Arquitetura
O AI-Dev é um ecossistema de desenvolvimento de software autônomo, assíncrono e multi-agente, estritamente focado na stack TALL (Tailwind, Alpine.js, Laravel, Livewire) + Filament v5 + Anime.js. Ele opera em background (headless), guiado por um banco de dados relacional e enriquecido por uma memória de longo prazo vetorial. As instruções trafegam em formato PRD (Product Requirement Document) para garantir clareza absoluta na comunicação entre os agentes.

## 2. Modelagem do Banco de Dados Relacional (Core) e Web UI
Diferente da versão inicial puramente headless, o AI-Dev contará com uma **Interface Web (UI)** desenvolvida em Filament v5. 
A interface servirá *exclusivamente* para gestão: cadastrar novos projetos, configurar o prompt dos agentes, e inserir tarefas/PRDs manualmente. O Orquestrador continua operando em background via *polling/events* nestas tabelas.

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

9. [FEEDBACK LOOP (Webhook do CI)]
   -> O Servidor de Testes roda testes.
   -> Se ERRO: Insere NOVA Task com log do erro na tabela. O ciclo reinicia sozinho (ou escala ao humano se crítico).
   -> Se SUCESSO: Salva o (PRD + Solução Validada) no Banco Vetorial. Status 'completed'.
```

## 4. Memória Persistente e Econômica (Fim do Markdown Infinito)

Em vez de salvar o histórico em um arquivo de texto (`memory.md`) que cresce eternamente e devora tokens (como visto em outros sistemas), o AI-Dev adota **Gestão de Contexto via Banco de Dados Relacional (MariaDB/SQLite)**. Isso permite buscar dados antigos sem embutir o histórico inteiro no *prompt*.

A gestão é dividida da seguinte forma:

*   **Compressão Ativa de Contexto (Short-term):** O Orquestrador e os Subagentes possuem uma **trava de compressão (threshold de 0.6)**. Quando a sessão atinge 60% do limite da janela de contexto, o sistema faz um reset forçado na sessão daquele agente e comprime os eventos anteriores em um parágrafo denso, avisando: *"Se não resetarmos o assunto, gastaremos muitos tokens à toa"*.
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

## 6. Arsenal de Ferramentas (The Tool Layer)

Com base no estudo das plataformas de referência (OpenClaude e OpenClaw), o AI-Dev implementará um catálogo estrito de **Ferramentas (Tools)**. O Orquestrador e os Subagentes invocarão essas ferramentas via Model Context Protocol (MCP) ou funções nativas JSON, garantindo ações precisas e com baixo risco de alucinação.

As ferramentas que comporão o nosso ecossistema incluem:

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
   * *Função:* Navegação e leitura estrutural de diretórios e arquivos.
   * *Uso Prático:* Listar árvores de diretórios ou ler o conteúdo bruto de arquivos inteiros (FileReadTool) de forma paginada para entendimento inicial de arquitetura.
5. **`DuckDuckGoSearchTool` (Pesquisa Web Direta):**
   * *Função:* Pesquisas na web não restritas.
   * *Uso Prático:* Buscar por documentações ou problemas (ex: `"Filament v3 upgrade guide site:github.com"`).
6. **`FirecrawlScraperTool` (O Fim do "Browser Use" Pesado):**
   * *Função:* Raspagem inteligente de páginas da web transformando HTML em puro Markdown limpo.
   * *Uso Prático:* Em vez de invocar um agente visual e pesado que clica na tela (desperdiçando tokens e dinheiro), usamos o Firecrawl para puxar a estrutura de dados de uma documentação mastigada para a LLM ler.
7. **`GitHubIntegrationTool` (Refatoração Inteligente):**
   * *Função:* Acesso à API do GitHub (Diffs, Commits, Pull Requests).
   * *Uso Prático:* Em vez de ficar lendo milhares de arquivos localmente como um "cego" buscando onde refatorar, a IA consulta o histórico de Commits ou Diffs do Github do projeto para pegar apenas o trecho alterado mais recentemente, ganhando muito contexto sem gastar tokens.
8. **`MarkdownDocsTool` (Inspirado no NotebookEditTool / Markdown):**
   * *Função:* Parsear, criar e atualizar documentações técnicas (.md).
   * *Uso Prático:* Manter arquivos como `README.md` ou `ARCHITECTURE.md` do projeto alvo constantemente atualizados conforme o código avança.
9. **`TaskTrackerTool` (Inspirado no TaskCreate / TodoWrite):**
   * *Função:* Capacidade de os agentes subdividirem lógicas ou anotarem pendências (TODOs) dinamicamente na execução.
   * *Uso Prático:* Se um Agente Backend perceber que falta uma View, ele anota isso dinamicamente na lista de tarefas para não perder o foco.
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
