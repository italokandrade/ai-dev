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

### 2.2. Motores LLM (Proxy Gemini, Claude Code e Ollama)
Para que o sistema opere com redundância e inteligência de elite:
*   **Proxy Gemini (O Executor Principal):** A ponte já existente nas portas 8000/8001 (`gemini_proxy.js`/`py`) para modelos como Gemini 3.1 Flash.
*   **Claude Code (O Cérebro de Elite):** Integração com o CLI oficial da Anthropic para acessar Claude 3.5 Sonnet 4.6 e Opus 4.6. Utilizado para planejamento e auditoria.
*   **Ollama (O "Compressor de Memória"):** Modelo ultraleve rodando em segundo plano para sumarização de contexto.
*   **Firecrawl Nativo (Web Scraper Limpo):** Hospedagem local para extração de Markdown.

### 2.3. Banco de Dados Vetorial (Memória de Longo Prazo e RAG)
O MariaDB cuida do relacionamento, mas não é rápido ou otimizado nativamente para buscas semânticas vetoriais. Para a funcionalidade de RAG (resgatar resoluções de bugs passados e injetar padrões few-shot no prompt):
*   **ChromaDB (Via Python) ou SQLite-Vec:** Recomenda-se a instalação de uma base de dados local focada em vetores. O ChromaDB roda levíssimo no ambiente Python já existente ou podemos compilar a extensão vetorial para o SQLite, garantindo consultas de milissegundos sem adicionar overhead ao servidor.

### 2.4. Core Application do AI-Dev (Backend + Web UI)
*   **Projeto Laravel Dedicado (ai-dev-core):** Precisaremos gerar um projeto Laravel 12 completo. Além de atuar como o "Cérebro" do nosso sistema orquestrando Jobs e Queues (via Horizon), ele proverá uma **Interface Web (UI)** baseada em Filament v5.
*   *Nota:* A interface servirá exclusivamente para cadastro/gerenciamento de Projetos, configuração de Agentes (system prompts) e inclusão manual de Tarefas/PRDs no banco de dados (monitorando seu status em tempo real).

---

**Resumo de Ação Futura (NÃO EXECUTAR AINDA):**
1. `apt install supervisor`
2. Configurar o Supervisor para o `gemini_watchdog.sh` (Proxy Gemini)
3. Instalar o Firecrawl nativamente no servidor (sem Docker).
4. Instalar o Ollama nativamente (`curl -fsSL https://ollama.com/install.sh | sh`) e rodar um modelo leve.
5. Instalar o Claude Code CLI (`npm install -g @anthropic-ai/claude-code`) e realizar login.
6. Setup ChromaDB no `/root/venv` (`pip install chromadb`)
7. Gerar o core do orquestrador via Laravel (`laravel new ai-dev-core`) e inicializar o painel Filament.
