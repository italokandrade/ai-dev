# Catálogo e Engenharia de Ferramentas (The Tool Layer)

O AI-Dev adota um catálogo expansivo e altamente categorizado de ferramentas (Tools) extraídas das melhores práticas do OpenClaude, OpenClaw e Hermes Agent. O uso estrito de ferramentas em vez de "raciocínio solto" reduz em até 90% as alucinações e falhas do sistema.

Todas as ferramentas operam sob contratos de schema JSON ou integração via Model Context Protocol (MCP).

---

## 1. Operações de Sistema e Execução (System & Code)

*   **`TerminalExecutorTool` (ex: `BashTool`):**
    *   *Objetivo:* Executar comandos de terminal no servidor com controle de estado, diretório (CWD) e timeouts rigorosos.
    *   *Uso no TALL:* `php artisan migrate`, `npm run dev`, git commands.
*   **`CodeExecutionSandboxTool` (ex: `code_execution_tool` do Hermes):**
    *   *Objetivo:* Executar snippets de código (PHP, Node, Python) de forma isolada no servidor apenas para ver o retorno antes de salvar o código real no projeto.
    *   *Uso no TALL:* Testar rapidamente um regex do PHP ou uma manipulação de array do Laravel *Collection* para garantir que funciona antes de injetar na View.
*   **`LSPTool` (Language Server Protocol):**
    *   *Objetivo:* Navegação semântica profunda na base de código (como um IDE).
    *   *Uso no TALL:* Ir para a definição (`Go to Definition`) de um Trait do Filament ou buscar todas as referências (`Find References`) de uma variável específica no sistema inteiro, superando a busca burra por texto.

## 2. Manipulação Cirúrgica de Arquivos (File System)

*   **`FileSurgeryTool` / `FileEditTool`:**
    *   *Objetivo:* Substituir a escrita cega. Usa S&R (Search and Replace) ou aplicação de Diff/Patch. O Agente deve buscar uma âncora (anchor) no código para trocar apenas a linha com defeito.
*   **`GlobSearchTool` e `GrepSearchTool`:**
    *   *Objetivo:* Varredura de metadados em alta velocidade sem leitura de conteúdo cega. O `Glob` busca caminhos de arquivos. O `Grep` busca palavras-chave (`x-data`, `@livewire`) usando regex no nível do OS (muito mais rápido que o LLM lendo arquivo por arquivo).
*   **`MarkdownDocsTool` (ex: `NotebookEditTool`):**
    *   *Objetivo:* Manipulação especializada para documentação, capaz de mesclar tabelas, criar índices e atualizar PRDs de forma nativa sem quebrar a estrutura.

## 3. Navegação Web e Raspagem (Web & Browser)

*   **`DuckDuckGoSearchTool`:**
    *   *Objetivo:* Consulta rápida à internet (API livre) para encontrar links, releases, ou discussões no GitHub/Laracasts sobre bugs em versões novas do Laravel.
*   **`FirecrawlScraperTool`:**
    *   *Objetivo:* Consumo limpo de documentação. Transforma o HTML de um site pesado (como a documentação do Livewire 3) em puro texto Markdown econômico, descartando lixo de DOM (CSS/JS) e otimizando tokens.
*   **`VisionBrowserTool` (ex: `browser_tool` / `vision_tools` do Hermes):**
    *   *Objetivo:* Quando os testes de UI do Laravel Dusk falham misteriosamente, o agente pode solicitar um "screenshot" da página em erro. A LLM usa suas capacidades multimodais de visão para "olhar" a página e ver se um modal do Alpine.js quebrou o layout do Tailwind, por exemplo.

## 4. Produtividade, Memória e Planejamento (Task & RAG)

*   **`TaskTrackerTool` (ex: `todo_tool` / `task_create_tool`):**
    *   *Objetivo:* Ferramenta de anotações dinâmicas. Durante a execução, se o subagente descobrir uma falha paralela, ele usa o `TaskTracker` para salvar um *TODO*. Isso mantém o agente no fluxo sem perder a linha de raciocínio principal.
*   **`SessionSearchTool` (ex: `memory_tool`):**
    *   *Objetivo:* Ferramenta de busca semântica para RAG vetorial. Permite ao agente pesquisar dentro do próprio histórico de projeto: *"Como foi mesmo que eu resolvi o erro do Vite.js ontem?"*. Evita perguntas redundantes ao usuário.
*   **`ClarifyTool` (ex: `AskUserQuestionTool`):**
    *   *Objetivo:* A parada de emergência. Usada exclusivamente quando a ambiguidade é crítica (ex: deletar uma tabela de banco de dados). Interrompe a autonomia, coloca a execução em pausa segura, e emite o aviso no Filament aguardando a resposta humana no painel.
*   **`SleepTool` / `CronjobTool`:**
    *   *Objetivo:* Quando um deploy demora ou uma API externa possui rate limit, a IA pode se colocar para dormir por *X* segundos e acordar depois, evitando chamadas vazias.

## 5. Mídia e Geração de Assets (Media Generation)

*   **`ImageGenerationTool`:**
    *   *Objetivo:* Conecta-se a um provedor externo (ex: DALLE-3 ou Stable Diffusion via OpenRouter).
    *   *Uso no TALL:* Em vez de criar um CRUD vazio, o agente pode gerar placeholders realistas de produtos (ex: "Foto de uma camisa de algodão") e salvá-los no Storage do Laravel, alimentando o banco de dados com conteúdo que faça sentido para o cliente visualizar no frontend.
*   **`IconographyTool` (Geração via Código):**
    *   *Objetivo:* Usando o modelo cognitivo, a IA pode ser instruída a gerar vetores (SVGs limpos e acessíveis) para injetar diretamente nas views Blade/Filament, evitando que o desenvolvedor tenha que procurar pacotes de ícones externos.

## 6. Ferramentas Locais AI-Dev (Custom TALL Tools)

*   **`SchemaExplorerTool`:**
    *   *Objetivo:* Ferramenta imutável que interage de forma *read-only* com o MariaDB. Executa um dump da tabela atual (`DESCRIBE`) para garantir que os nomes das colunas que o Agente vai usar no Eloquent ORM estão perfeitamente alinhados com o estado do banco.
*   **`GitHubIntegrationTool`:**
    *   *Objetivo:* API do GitHub em formato de ferramenta para leitura de Diffs e Commits. Se a IA precisar entender o contexto da refatoração de um colega humano, ela puxa o último PR e analisa o escopo macro em vez de fuçar pastas localmente.