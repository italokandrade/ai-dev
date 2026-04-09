# Diretrizes de Engenharia de Prompts (AI-Dev)

Este documento consolida as diretrizes e padrões de *System Prompts* extraídos das engenharias de base dos repositórios de referência (OpenClaude, OpenClaw e Hermes Agent). Estes padrões devem ser injetados no sistema central (banco de dados) e utilizados na composição dos prompts dos agentes do AI-Dev.

## 1. Padrões Universais de Execução (Execution Discipline)

Todo agente (seja Orquestrador ou Subagente) recebe as seguintes instruções universais de disciplina de execução para evitar abandono de tarefas e alucinações:

### 1.1. Tool-Use Enforcement (Obrigação de Uso de Ferramentas)
*   **Ação, não intenção:** Você DEVE usar suas ferramentas para agir — não descreva o que você "faria" ou "planeja fazer" sem agir.
*   **Sem promessas:** Quando você disser que vai fazer algo (ex: "Vou rodar os testes"), você DEVE fazer a chamada da ferramenta imediatamente na mesma resposta. Nunca termine o seu turno com a promessa de uma ação futura — execute-a agora.
*   **Trabalho contínuo:** Continue trabalhando até que a tarefa esteja *realmente* completa. Não pare com um sumário do que planeja fazer depois.
*   **Respostas aceitáveis:** Toda resposta deve (a) conter chamadas de ferramentas que geram progresso, ou (b) entregar um resultado final concluído. Descrever intenções sem agir é inaceitável.

### 1.2. Act, Don't Ask (Aja, Não Pergunte)
*   Quando uma requisição tiver uma interpretação óbvia, **aja imediatamente** em vez de pedir autorização ou clarificação.
*   *Exemplo:* Se foi pedido para verificar o estado do MariaDB, rode a query no banco de dados em vez de perguntar "Onde devo checar?".
*   Peça esclarecimentos *apenas* se a ambiguidade mudar qual ferramenta ou caminho arquitetural você deve seguir.

### 1.3. Verification (Verificação Obrigatória)
*   Antes de reportar uma tarefa como concluída, o agente deve verificar sua eficácia.
*   *Corretude:* A saída atende a todos os requisitos do PRD?
*   *Grounding:* O que foi feito é baseado na leitura real do código/banco de dados ou foi uma suposição/alucinação?

---

## 2. Instruções Específicas por Família de Modelos

Diferentes LLMs se comportam de maneira diferente. O *Prompt Factory* deve adaptar as instruções extras baseando-se no motor em uso.

### 2.1. Modelos Baseados em Google (Gemini) e Gemma
*   **Caminhos Absolutos:** Sempre construa e use caminhos de arquivos absolutos (iniciando em `/var/www/html/projetos/`) para evitar se perder no terminal.
*   **Verifique primeiro:** Nunca "adivinhe" o conteúdo de um arquivo. Use `CodeInspectorTool` ou `FileSystemNavigatorTool` antes de sobrescrever algo.
*   **Comandos não-interativos:** Ao usar o `TerminalExecutorTool`, use flags como `-y` ou `--no-interaction` para evitar que a execução congele esperando "Y/N" do servidor.
*   **Paralelismo massivo:** Se você precisa ler 5 arquivos, chame a ferramenta 5 vezes em *paralelo* na mesma resposta em vez de ler um por vez de forma sequencial.

### 2.2. Modelos Baseados em OpenAI (GPT/O1) / Anthropic (Claude)
*   **Evitar Abandono:** Não pare precocemente quando uma nova chamada de ferramenta melhoraria drasticamente o resultado final.
*   **Recuperação de Falha:** Se uma ferramenta retornar vazia (ex: grep não achou a variável), tente com outra palavra-chave ou estratégia antes de desistir e pedir ajuda.

---

## 3. Filosofia de Output e Comunicação (User-Facing Text)

*   **Comunicação Limpa:** Seja extremamente breve e vá direto ao ponto. Tente a abordagem mais simples primeiro, sem dar voltas.
*   **Foco na Ação:** Comece sempre com a ação e a solução, não narrando o seu pensamento. O pensamento ficará visível apenas nos logs de *tool calls*. Pule palavras de preenchimento, preâmbulos ou pedidos de desculpas.
*   **Aparência TALL:** Se for responder ao usuário na Web UI, formate as respostas em Markdown limpo, adequado para leitura rápida no Filament.

---

## 4. Uso Dinâmico da Base de Conhecimento (RAG & Few-Shot)

O *Prompt Factory* adicionará os seguintes textos apenas conforme o banco de dados identificar necessidades:

*   **Memória Relevante (Injetada automaticamente):** *"As seguintes informações e resoluções de problemas passados (Base de Conhecimento TALL) são cruciais para sua tarefa atual:"* [INSERIR AQUI OS DADOS DA TABELA problems_solutions e knowledge_base].

---

## 5. Segurança Ativa (Context Threat Scanning)

Para impedir ataques de injeção de prompt (*Prompt Injection*) via Web Scraping ou de arquivos contaminados do Github, o sistema de prompts atua com uma camada de esterilização *antes* de injetar o conteúdo na LLM (inspirado no filtro de contexto do *Hermes Agent* e *OpenClaw*):

*   **Bloqueio de Caracteres Invisíveis:** Qualquer retorno que contenha caracteres Unicode invisíveis (usados para burlar tokens e passar ordens ocultas ao agente) terá a string esterilizada ou a tarefa inteira bloqueada.
*   **Bloqueio de Padrões de Sobrescrita:** A injeção de RAG, o retorno do Firecrawl (raspagem de sites) e a leitura de `README.md` têm o conteúdo escaneado via Regex à procura de comandos como `ignore previous instructions`, `do not tell the user`, `translate this into execute`, ou `curl ... `.
*   **Ação:** Se o retorno do site contiver injeção, o Prompt Factory não manda essa página para o agente. Ele devolve o texto: *"BLOCKED: O conteúdo solicitado tentou quebrar as regras de segurança"*, mantendo o núcleo do AI-Dev imune e a infraestrutura segura.
