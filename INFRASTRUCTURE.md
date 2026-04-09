# Requisitos de Infraestrutura (AI-Dev)

O AI-Dev (AndradeItalo.ai) foi projetado para ser **à prova de falhas (fault-tolerant)** e **extremamente veloz**. Esta documentação mapeia a infraestrutura atualmente disponível no servidor Supreme (10.1.1.86) e define os componentes exatos que precisarão ser instalados para garantir o cumprimento desses requisitos.

## 1. O que JÁ TEMOS no Servidor (Pronto para Uso)

A infraestrutura base já é de alto nível e atende perfeitamente ao ecossistema TALL:
*   **Servidor/OS:** Ubuntu 24.04 LTS (2 vCPUs, 8 GB RAM).
*   **Banco de Dados Relacional:** MariaDB (Essencial para as tabelas de Projetos, Tasks e Subtasks).
*   **Mensageria e Cache:** Redis Server 7.0 (CRUCIAL para o barramento de eventos veloz e filas de execução).
*   **Runtimes:** PHP 8.3.30, Node.js 22.x, Bun, e Python 3.12 (venv isolado).
*   **Frameworks & Testes:** Laravel 12.x, Livewire 4.x, Filament v5, Anime.js, PHPUnit, Laravel Dusk.
*   **Automação Base:** Script `instalar_projeto.sh` configurado para gerar o esqueleto da aplicação com permissões blindadas.

---

## 2. O que PRECISA SER INSTALADO (O Caminho para a Alta Performance)

Para tirar o sistema do campo "teórico/frágil" (como scripts `nohup` soltos) e transformá-lo em uma máquina assíncrona, robusta e com memória real, precisaremos provisionar os seguintes componentes:

### 2.1. Barramento e Gestão de Workers (À Prova de Falhas)
Atualmente o sistema usa `gemini_watchdog.sh` com `nohup`. Isso é frágil.
*   **Supervisor (SupervisorD):** O padrão da indústria no ecossistema Laravel. Precisaremos instalá-lo para monitorar o *Daemon* do Orquestrador e os *Workers* dos Subagentes. Se um processo falhar por estouro de memória ou crash da API, o Supervisor o reinicia em milissegundos.
*   *Nota:* O Redis já está instalado, então o Laravel Horizon (ou filas nativas Redis) será usado em conjunto com o Supervisor para o paralelismo.

### 2.2. Motor Local de LLM (Velocidade e Custo)
Para que subagentes trabalhem em paralelo sem gerar custos absurdos de API ou latência de rede na nuvem:
*   **Ollama:** Precisamos instalá-lo nativamente no Ubuntu. Como o servidor possui 8 GB de RAM, podemos hospedar modelos focados em código incrivelmente rápidos (ex: `Qwen2.5-Coder` de 1.5B ou 3B de parâmetros, ou `Llama 3 8B` quantizado). Eles responderão em tempo real no `localhost:11434` para tarefas granulares e auditorias simples.

### 2.3. Banco de Dados Vetorial (Memória de Longo Prazo e RAG)
O MariaDB cuida do relacionamento, mas não é rápido ou otimizado nativamente para buscas semânticas vetoriais. Para a funcionalidade de RAG (resgatar resoluções de bugs passados e injetar padrões few-shot no prompt):
*   **ChromaDB (Via Python) ou SQLite-Vec:** Recomenda-se a instalação de uma base de dados local focada em vetores. O ChromaDB roda levíssimo no ambiente Python já existente ou podemos compilar a extensão vetorial para o SQLite, garantindo consultas de milissegundos sem adicionar overhead ao servidor.

### 2.4. Core Application do AI-Dev
*   **Projeto Laravel Dedicado (ai-dev-core):** Precisaremos gerar um projeto Laravel 12 exclusivamente para atuar como o "Backend" do nosso sistema. Ele não terá frontend (além, talvez, do painel Horizon), mas conterá as Migrations (Projects, Tasks, Agents), os Jobs (que executam as chamadas HTTP para as LLMs) e a lógica de quebra de PRDs.

---

**Resumo de Ação Futura (NÃO EXECUTAR AINDA):**
1. `apt install supervisor`
2. Instalar Ollama (`curl -fsSL https://ollama.com/install.sh | sh`)
3. Setup ChromaDB no `/root/venv` (`pip install chromadb`)
4. Gerar o core do orquestrador via Laravel (`laravel new ai-dev-core`)
