# Catálogo e Engenharia de Ferramentas (The Tool Layer)

O AI-Dev adota o **Padrão de Injeção de Comandos (Command-Injection Pattern)**. Nesta arquitetura, a inteligência artificial não escreve comandos complexos ou scripts do zero a cada vez; ela gera apenas os **dados, parâmetros ou códigos brutos** que serão injetados em ferramentas (scripts) pré-existentes e otimizadas no servidor.

Este modelo economiza tokens drasticamente e garante que a execução ocorra dentro dos padrões de segurança do servidor Locaweb.

---

## 1. Ferramentas de Operação TALL (Pre-built Operations)

O servidor possui scripts prontos para as seguintes operações, aguardando apenas a injeção de dados pela IA:

*   **`ArtisanExecutorTool`:** Scripts prontos para `migrate`, `make:filament-resource`, `optimize:clear`, etc. A IA envia apenas o nome da classe ou os campos.
*   **`DatabaseOperatorTool`:**
    *   *Dumps:* Scripts prontos para realizar backup/dump de tabelas específicas.
    *   *Listagem:* Script otimizado que recebe parâmetros (tabela, colunas, filtros) e devolve o JSON dos registros.
*   **`GitFlowTool`:** Ferramentas padronizadas para `commit`, `pull`, `push` e `merge` que garantem que nenhuma alteração se perca.
*   **`DependencyManagerTool`:** Injeção de pacotes via `composer` e `npm` com validação de versão.

## 2. Meta-Ferramenta: Evolução do Sistema

*   **`ToolCreatorTool` (Criação de Ferramentas):**
    *   Sempre que o Orquestrador detectar a necessidade de uma operação que ainda não possui uma ferramenta pronta e que seja de **uso comum**, o sistema deve ser capaz de **criar a nova ferramenta**.
    *   *Regra de Ouro:* É proibida a criação de "gambiarras". Novas ferramentas só devem ser criadas se houver utilidade futura recorrente. Uma vez criada e validada, ela passa a integrar o arsenal fixo para todos os agentes.

---

## 3. Tratamento de Falhas e Impossibilidades Técnicas

Se o Orquestrador ou um Agente não conseguir executar uma tarefa por qualquer limitação técnica ou ambiguidade crítica:
1.  A execução é interrompida imediatamente.
2.  O motivo detalhado da impossibilidade é registrado no campo de **observações da tarefa** no banco de dados MariaDB.
3.  A tarefa é escalada para o painel Filament, permitindo que você (o humano) tome a decisão ou trate o caso manualmente.

---

## 4. Manipulação Cirúrgica de Arquivos... (restante das ferramentas mantidas)

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
    *   *Objetivo:* API do GitHub em formato de ferramenta para leitura de Diffs e Commits. Se a IA precisar entender o contexto da refatoração de um colega humano, ela puxa o último PR e analisa o escopo macro em vez de fuçar pastas localmente.nt ORM estão perfeitamente alinhados com o estado do banco.
*   **`GitHubIntegrationTool`:**
    *   *Objetivo:* API do GitHub em formato de ferramenta para leitura de Diffs e Commits. Se a IA precisar entender o contexto da refatoração de um colega humano, ela puxa o último PR e analisa o escopo macro em vez de fuçar pastas localmente.