# Requisitos de Infraestrutura (AI-Dev)

O AI-Dev (AndradeItalo.ai) foi projetado para ser **à prova de falhas (fault-tolerant)** e **extremamente veloz**. Esta documentação mapeia a infraestrutura atualmente disponível no servidor Supreme (10.1.1.86) e define os componentes exatos que precisarão ser instalados para garantir o cumprimento desses requisitos.

---

## 1. O que JÁ TEMOS no Servidor (Pronto para Uso)

A infraestrutura base já é de alto nível e atende perfeitamente ao ecossistema TALL:

| Componente | Versão | Papel no AI-Dev |
|---|---|---|
| **Ubuntu 24.04 LTS** | 24.04.3 | Sistema operacional do servidor (2 vCPUs, 8 GB RAM) |
| **PostgreSQL** | 16.13 | Banco de dados relacional CORE — tabelas de projetos, tasks, subtasks, logs |
| **Redis Server** | 7.0.15 | Barramento de eventos, filas de execução (Laravel Queue), cache, Pub/Sub |
| **PHP** | 8.3.30 | Runtime do Laravel 13 — onde roda o AI-Dev Core |
| **Node.js** | 22.22.2 | Runtime para npm scripts, compilação de assets (Vite/Tailwind) |
| **Bun** | 1.3.11 | Runtime alternativo para scripts auxiliares e proxies |
| **Python** | 3.12.3 (venv) | Runtime para proxies (Gemini/Claude) e scripts auxiliares |
| **Composer** | 2.9.5 | Gerenciador de pacotes PHP |
| **Laravel** | 13.5.0 | Framework base do AI-Dev Core |
| **Livewire** | 4.2.4 | Interatividade real-time na Web UI |
| **Filament** | 5.5.0 | Painel administrativo — Dashboard, Resources, gestão de projetos e tasks |
| **Laravel AI SDK** | 0.5.0 | Agents, Tools, Embeddings, Vector Stores, Structured Output |
| **Laravel Boost** | 2.4.4 | MCP server: database-schema, search-docs, browser-logs, tinker |
| **Laravel Horizon** | 5.45.5 | Dashboard e gestão de filas Redis |
| **Pest v4** | 4.6.3 | Testes automatizados (PHPUnit v12 como base) |
| **Tailwind CSS** | v4 | Estilização dos projetos e UI do AI-Dev Core |
| **Anime.js** | Latest | Animações na UI do Filament e projetos |
| **Script `instalar_projeto.sh`** | Custom | Geração do esqueleto de aplicações com permissões blindadas |

**O que isso significa na prática:** O servidor já tem TUDO necessário para rodar a aplicação Laravel do AI-Dev Core. Não precisamos instalar PHP, Node, banco de dados, nem frameworks. O que falta são os componentes de **orquestração**, **IA** e **memória vetorial**.

---

## 2. O que PRECISA SER INSTALADO (O Caminho para a Alta Performance)

Para tirar o sistema do campo "teórico/frágil" (como scripts `nohup` soltos) e transformá-lo em uma máquina assíncrona, robusta e com memória real, precisaremos provisionar os seguintes componentes:

### 2.1. Supervisor (Orquestração de Workers) — PRIORIDADE MÁXIMA

**O que é:** O Supervisor é o padrão da indústria para monitorar processos de longa duração no Linux. Ele substitui o uso frágil de `nohup` e `&` para rodar scripts em background.

**Por que precisamos:** Atualmente o sistema usa `gemini_watchdog.sh` com `nohup`. Se esse script crashar (estouro de memória, timeout de API, falha de rede), ele morre silenciosamente e ninguém percebe. O Supervisor reinicia o processo automaticamente em milissegundos.

**O que ele vai monitorar no AI-Dev:**

| Programa | Arquivo de Config | Workers | Fila Redis |
|---|---|---|---|
| Orchestrator Worker | `/etc/supervisor/conf.d/aidev-orchestrator.conf` | 1 | `queue:orchestrator` |
| Executor Workers | `/etc/supervisor/conf.d/aidev-executors.conf` | 3 | `queue:executors` |
| QA Auditor Worker | `/etc/supervisor/conf.d/aidev-qa.conf` | 1 | `queue:qa-auditor` |
| Security Worker | `/etc/supervisor/conf.d/aidev-security.conf` | 1 | `queue:security` |
| Performance Worker | `/etc/supervisor/conf.d/aidev-performance.conf` | 1 | `queue:performance` |
| Context Compressor | `/etc/supervisor/conf.d/aidev-compressor.conf` | 1 | `queue:compressor` |
| Sentinel Watcher | `/etc/supervisor/conf.d/aidev-sentinel.conf` | 1 | `queue:sentinel` |
| Gemini Proxy | `/etc/supervisor/conf.d/gemini-proxy.conf` | 1 | N/A (porta 8001) |
| Claude Proxy | `/etc/supervisor/conf.d/claude-proxy.conf` | 1 | N/A (porta 8002) |

**Exemplo de config do Supervisor para o Orchestrator Worker:**

```ini
[program:aidev-orchestrator]
process_name=%(program_name)s
command=php /var/www/html/projetos/ai-dev-core/artisan queue:work redis --queue=orchestrator --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/projetos/ai-dev-core/storage/logs/orchestrator-worker.log
stopwaitsecs=3600
```

**Instalação:**
```bash
# 1. Instalar
sudo apt install supervisor -y

# 2. Criar os configs (um por programa)
sudo nano /etc/supervisor/conf.d/aidev-orchestrator.conf

# 3. Recarregar
sudo supervisorctl reread
sudo supervisorctl update

# 4. Verificar status
sudo supervisorctl status
```

**Integração com Laravel Horizon (Alternativa ao queue:work puro):**
O Laravel Horizon é um dashboard web para monitorar filas Redis. Ele se integra com o Supervisor e oferece métricas visuais (jobs processados, falhados, tempo médio). Recomendado instalar depois do MVP 1:

```bash
cd /var/www/html/projetos/ai-dev-core
composer require laravel/horizon
php artisan horizon:install
php artisan horizon:publish
```

O Horizon substituiría o `queue:work` nos configs do Supervisor por `php artisan horizon`.

---

### 2.2. Motores LLM

O sistema agêntico usa dois providers externos para os agents, e dois proxies locais reservados para infraestrutura.

#### Providers ativos (agents do AI-Dev Core)

Todo o sistema agêntico usa um único provider externo: **OpenRouter** com família Anthropic.

| Provider | Modelo | Tier | Agents |
|---|---|---|---|
| **openrouter** | `anthropic/claude-opus-4-7` | Máxima qualidade | OrchestratorAgent, SpecificationAgent, QuotationAgent, RefineDescriptionAgent |
| **openrouter** | `anthropic/claude-sonnet-4-6` | Qualidade + custo | SpecialistAgent, QAAuditorAgent |
| **openrouter** | `anthropic/claude-haiku-4-5-20251001` | Rápido + barato | DocsAgent |
| **ollama** (local) | `qwen2.5:0.5b` | Sem custo API | ContextCompressor (Fase 3) |

Configurados em `config/ai.php` e `.env` do AI-Dev Core. Único `.env` necessário: `OPENROUTER_API_KEY`.

#### Proxies locais (infraestrutura reservada)

| Proxy | Porta | Status | Uso |
|---|---|---|---|
| Gemini Proxy (Python Flask) | 8001 | ✅ Operacional | Reservado — não usado pelos agents atualmente |
| Claude Proxy | 8002 | ✅ Operacional | Reservado — não usado pelos agents atualmente |

Os proxies são mantidos como infraestrutura de fallback para uso futuro em módulos da aplicação. Gerenciados via watchdog (`/root/gemini_watchdog.sh`).

#### Ollama (O Compressor de Memória)

**Status:** ❌ Precisa ser instalado.

**O que é:** Runtime local para modelos de IA. Roda modelos no próprio servidor SEM depender de API externa, SEM custo por token, SEM enviar dados para fora.

**Por que precisamos:** 
1. **Compressão de contexto:** Quando a sessão de um agente atinge 60% da janela, o Ollama comprime o histórico em um resumo denso. Usar Gemini/Claude para isso seria desperdício de dinheiro e tokens.
2. **Geração de embeddings:** Para o RAG vetorial (busca semântica na tabela `problems_solutions`), precisamos vetorizar textos. O modelo `nomic-embed-text` gera embeddings localmente sem custo.

**Modelos que instalaremos:**

| Modelo | Tamanho | RAM | Uso no AI-Dev |
|---|---|---|---|
| `qwen2.5:0.5b` | 395 MB | ~500 MB | Compressão de contexto (resumir histórico) |
| `nomic-embed-text` | 274 MB | ~350 MB | Geração de embeddings para RAG vetorial |

**Por que esses modelos especificamente?**
- `qwen2.5:0.5b` é o menor modelo capaz de sumarização decente. Cabe em memória sem afetar o servidor (que tem 8 GB RAM).
- `nomic-embed-text` é o modelo de embedding mais eficiente rodando via Ollama. Gera vetores de 768 dimensões.

**Instalação:**
```bash
# 1. Instalar Ollama nativamente (sem Docker)
curl -fsSL https://ollama.com/install.sh | sh

# 2. Iniciar o serviço (roda na porta 11434 por default)
sudo systemctl enable ollama
sudo systemctl start ollama

# 3. Baixar os modelos
ollama pull qwen2.5:0.5b
ollama pull nomic-embed-text

# 4. Verificar
ollama list
curl http://localhost:11434/api/generate -d '{"model":"qwen2.5:0.5b","prompt":"Olá","stream":false}'
```

**Consumo de RAM estimado:** ~850 MB com ambos os modelos carregados. O servidor com 8 GB suporta tranquilamente (PHP + PostgreSQL + Redis usam ~2-3 GB, sobrando ~4-5 GB).

---

### 2.3. Banco de Dados Vetorial (Memória de Longo Prazo e RAG)

**Status:** ⏳ **Pendente** — pgvector está disponível no PostgreSQL 16 mas ainda não foi habilitado no banco `ai_dev_core`. Habilitar quando iniciar a Fase 3 (RAG).

**A solução adotada:** **pgvector** nativo no PostgreSQL 16. Eliminamos completamente a necessidade de ChromaDB ou SQLite-Vec — a busca vetorial roda no MESMO banco de dados relacional, sem serviço extra, sem RAM adicional, sem manutenção.

**Como funciona:**

```sql
-- Coluna vector na tabela problems_solutions (já na migration)
ALTER TABLE problems_solutions ADD COLUMN embedding vector(1536);

-- Criar índice HNSW para busca aproximada rápida
CREATE INDEX ON problems_solutions USING hnsw (embedding vector_cosine_ops);
```

**Integração no AI-Dev via Laravel AI SDK:**
```php
// Gerar embedding via SDK
$embedding = AI::embeddings()->provider(Lab::Ollama)->embed($prdText);

// Busca semântica via Eloquent (whereVectorSimilarTo — macro pgvector)
$solutions = ProblemSolution::query()
    ->whereVectorSimilarTo('embedding', $prdText, minSimilarity: 0.7)
    ->limit(3)
    ->get();

// Ou via toEmbeddings() macro no Stringable
$embedding = str($prdText)->toEmbeddings()->provider(Lab::Ollama)->embed();
```

**Prós de usar pgvector em vez de ChromaDB:**

| | pgvector (escolhido) | ChromaDB (descartado) |
|---|---|---|
| **Instalação** | Já instalado | pip install + serviço extra |
| **Serviço extra** | Não (mesmo PostgreSQL) | Sim (processo Python) |
| **RAM extra** | 0 MB | ~200 MB |
| **Joins com tabelas** | SQL nativo | API HTTP |
| **Backup** | Junto com pg_dump | Separado |
| **Manutenção** | Zero | Versões Python, venv |

---

### 2.4. Firecrawl Self-Hosted (Web Scraper Limpo)

**O que é:** Motor de web scraping que converte páginas HTML em Markdown puro e limpo. O AI-Dev usa para ler documentação técnica (Laravel, Filament, etc.) e injetar como contexto no prompt.

**Por que self-hosted?** A API paga do Firecrawl ($19/mês básico) é desnecessária se temos a capacidade de hospedar o motor localmente. Self-hosted = 100% gratuito, privacidade total, controle absoluto.

**Instalação (sem Docker):**
```bash
# 1. Clonar o repositório
cd /opt
git clone https://github.com/mendableai/firecrawl.git

# 2. Instalar dependências Node
cd firecrawl/apps/api
npm install

# 3. Configurar variáveis
cp .env.example .env
# Editar .env: FIRECRAWL_PORT=3002

# 4. Iniciar
npm run start

# 5. Adicionar ao Supervisor
# /etc/supervisor/conf.d/firecrawl.conf
```

**Alternativa mais leve (se Firecrawl for pesado demais):**
Usar diretamente o `readability-cli` (Mozilla):
```bash
npm install -g @nicolo-ribaudo/readability-cli
readable "https://filamentphp.com/docs/3.x/panels/resources" --format markdown
```
Esta alternativa consome muito menos RAM e é suficiente para o MVP.

---

### 2.5. Ferramentas de Segurança e Pentest (100% Gratuitas)

Para o Security Specialist operar, precisamos de ferramentas de análise de código, scan de servidor e teste de penetração. Todas as ferramentas abaixo são **open-source e gratuitas**:

#### Enlightn OSS (Análise Estática Laravel)

**O que é:** Package Composer que roda 66 verificações automatizadas de segurança, performance e confiabilidade em projetos Laravel. É instalado POR PROJETO, não globalmente.

**Instalação (no projeto alvo):**
```bash
cd /var/www/html/projetos/{nome_do_projeto}
composer require enlightn/enlightn --dev
php artisan enlightn
```

**Verificações incluídas (gratuitas):**
- Debug mode em produção, cookies inseguros, CSRF desabilitado
- Mass assignment (Models sem $fillable/$guarded)
- SQL injection por concatenação de strings
- Headers de segurança faltando (X-Frame-Options, CSP, etc.)
- N+1 queries, cache não configurado, middleware desnecessário

#### Larastan / PHPStan (Análise Estática de Tipos)

**O que é:** Extensão do PHPStan específica para Laravel. Detecta bugs de tipo, variáveis undefined, imports faltando e chamadas de método inválidas — SEM executar o código.

**Instalação (no projeto alvo):**
```bash
cd /var/www/html/projetos/{nome_do_projeto}
composer require larastan/larastan --dev

# Criar phpstan.neon na raiz do projeto
cat > phpstan.neon << 'EOF'
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths:
        - app
    level: 6
EOF

# Executar
./vendor/bin/phpstan analyse
```

**Por que nível 6?** Níveis 0-5 são permissivos demais e perdem bugs reais. Nível 9 é agressivo demais e gera centenas de falsos positivos. Nível 6 é o equilíbrio ideal para projetos Laravel.

#### Nikto (Scanner de Servidor Web)

**O que é:** Scanner open-source que verifica ~7000 vulnerabilidades em servidores web: versões outdated, diretórios expostos, configurações inseguras, headers faltando.

**Instalação (global no servidor):**
```bash
sudo apt install nikto -y

# Verificar instalação
nikto -Version

# Uso básico
nikto -h http://portal.test -o /tmp/nikto_report.txt -Format txt
```

**Importante:** Nikto faz milhares de requisições. Rodar APENAS em staging/development.

#### SQLMap (Teste de SQL Injection Automatizado)

**O que é:** Ferramenta Python de pentest que detecta e explora vulnerabilidades de SQL injection automaticamente. Suporta MySQL, MariaDB, PostgreSQL, Oracle e muitos outros.

**Instalação (via Python — já temos o venv):**
```bash
source /root/venv/bin/activate
pip install sqlmap

# Uso seguro (modo non-destructive)
python3 -m sqlmap -u "http://portal.test/api/posts?id=1" --batch --level=1 --risk=1

# Testar todos os formulários de uma página
python3 -m sqlmap -u "http://portal.test/login" --forms --batch --level=1 --risk=1
```

**⚠️ REGRA ABSOLUTA:** NUNCA rodar SQLMap contra servidores em produção. Apenas em ambiente de staging/development local.

#### Composer Audit + NPM Audit (Auditoria de Dependências)

**O que é:** Ferramentas NATIVAS do Composer e npm que verificam se as dependências do projeto têm CVEs (vulnerabilidades) conhecidas.

**Instalação:** Nenhuma — já vem com Composer 2.4+ e npm.

```bash
# Verificar dependências PHP
cd /var/www/html/projetos/{nome_do_projeto}
composer audit

# Verificar dependências JavaScript
npm audit --json
```

**Custo:** $0 (zero). Todas as ferramentas são 100% open-source.
**RAM:** Negligível — todas rodam sob demanda e encerram ao finalizar.

---

### 2.6. Core Application do AI-Dev (Backend + Web UI)

**Status:** ✅ **JÁ INSTALADO E OPERACIONAL** em `/var/www/html/projetos/ai-dev/ai-dev-core`

**Versões instaladas:**
- Laravel 13.5.0 + PHP 8.3.30
- Filament v5.5.0 + Livewire v4.2.4
- Laravel AI SDK v0.5.0 + Laravel Boost v2.4.4 + Laravel MCP v0.6.7
- Laravel Horizon v5.45.5 + Pest v4.6.3
- PostgreSQL 16.13 + Redis 7.0.15

**Providers de IA configurados (`config/ai.php`):**
- `openrouter` → provider único — família Anthropic: `claude-opus-4-7` (planejamento), `claude-sonnet-4-6` (código/QA), `claude-haiku-4-5-20251001` (docs)
- `ollama` → ContextCompressor local `qwen2.5:0.5b` (Fase 3, sem custo API)

**Para manutenção:**
```bash
cd /var/www/html/projetos/ai-dev/ai-dev-core

# Rodar migrations
php artisan migrate

# Rodar testes
php artisan test --compact

# Limpar caches após mudar config
php artisan config:clear && php artisan cache:clear
```

---

## 3. Consumo de Recursos Estimado (O Servidor Aguenta?)

Com tudo instalado e rodando simultaneamente:

| Componente | RAM Estimada | CPU | Disco |
|---|---|---|---|
| Ubuntu 24.04 + PHP-FPM | ~500 MB | Baixo | N/A |
| PostgreSQL | ~450 MB | Moderado | ~500 MB (dados) |
| Redis 7 | ~200 MB | Baixo | ~100 MB |
| Laravel (AI-Dev Core) | ~150 MB | Moderado | ~200 MB |
| Laravel Queue Workers (9x) | ~900 MB | Variável | N/A |
| Ollama (2 modelos) | ~850 MB | Pico durante inferência | ~700 MB |
| pgvector | 0 MB extra | Incluso no PostgreSQL | 0 MB extra |
| Node.js (Firecrawl) | ~300 MB | Baixo | ~200 MB |
| Security Tools (sob demanda) | ~100 MB | Pico durante scan | ~50 MB |
| **TOTAL** | **~3.4 GB** | **Variável** | **~1.75 GB** |

**Veredicto:** O servidor com **8 GB de RAM** suporta confortavelmente toda a stack com ~4.4 GB de folga para picos. As ferramentas de segurança (Enlightn, PHPStan, Nikto, SQLMap) rodam sob demanda e encerram ao finalizar, então o consumo real é intermitente. As 2 vCPUs são o gargalo principal — inferência LLM local (Ollama) consome CPU durante execução, mas o modelo é tão leve (0.5B params) que infere em ~1-2 segundos.

**Recomendação de escala futura:** Se o sistema crescer para 5+ projetos simultâneos com 10+ tasks em paralelo, considerar upgrade para 4 vCPUs e 16 GB RAM.

---

## 4. Resumo de Ação por Fase (Alinhado com ARCHITECTURE.md seção 10)

### Fase 1: Core Loop (NÃO precisa de Ollama nem Firecrawl)

```bash
# Apenas o essencial para o ciclo Task → Orchestrator → Subagente → QA → Commit
1. Projeto Laravel 13 ai-dev-core já existe em /var/www/html/projetos/ai-dev/ai-dev-core/
2. Configurar ANTHROPIC_API_KEY e GEMINI_API_KEY no .env
3. Rodar migrations: php artisan migrate
4. Configurar Laravel Horizon (já instalado) para workers
5. Configurar Supervisor para 4 workers (orchestrator, executors, qa, default)
6. Implementar Agent classes + Tool classes
7. Testar ciclo completo com 1 task simples
```

### Fase 2: Qualidade, Segurança e UI (Adiciona Sentinel + Security Tools)

```bash
# Adiciona capacidade de auditoria de segurança e performance
1. Configurar ANTHROPIC_API_KEY no .env (Claude para OrchestratorAgent e QAAuditorAgent)
2. Instalar ferramentas de segurança no servidor:
   sudo apt install nikto -y
   source /root/venv/bin/activate && pip install sqlmap
3. Instalar Enlightn + Larastan nos projetos alvo:
   composer require enlightn/enlightn larastan/larastan --dev
4. Implementar SecurityAuditJob + PerformanceAnalysisJob
5. Configurar Supervisor para workers de segurança e performance
6. Criar Sentinel (Exception Handler nos projetos alvo)
7. Implementar circuit breakers (limites de custo, retries, tempo)
```

### Fase 3: IA Avançada (Adiciona Ollama + Firecrawl + RAG pgvector)

```bash
1. curl -fsSL https://ollama.com/install.sh | sh
2. ollama pull qwen2.5:0.5b && ollama pull nomic-embed-text
3. Habilitar extensão pgvector (já disponível no PostgreSQL 16 do servidor)
4. Rodar migration problems_solutions com coluna vector(1536)
5. Instalar Firecrawl self-hosted (Node.js — já temos Node 22.x)
6. Implementar ContextCompressionJob + RAG (whereVectorSimilarTo)
7. (Opcional) Instalar OWASP ZAP para scan DAST avançado
8. Configurar Supervisor para Ollama worker
```

---

## 7. Sincronização de Conhecimento (Docs Oficiais)

Para garantir que os agentes e as IAs utilizem sempre a "fonte da verdade" mais atualizada da TALL Stack, o servidor possui scripts de sincronização automática.

### 7.1. Sincronia GitHub → Local
O script `baixar_docs_tall.py` espelha as documentações oficiais dos repositórios GitHub para o diretório local.

- **Caminho Local:** `/var/www/html/projetos/ai-dev/docs_tecnicos/`
- **Execução:**
  ```bash
  python3 /var/www/html/projetos/ai-dev/baixar_docs_tall.py
  ```
- **Comportamento:** Espelhamento incremental (baixa novos, atualiza modificados e deleta arquivos removidos no remoto via `git clean`).

### 7.2. Sincronia Local → OpenAI Storage
O script `sync_openai_storage.py` envia os arquivos Markdown locais para um Vector Store na OpenAI, permitindo que Assistants tenham conhecimento técnico profundo.

- **Vector Store:** `TALL_STACK_SUPREME_DOCS`
- **Execução:**
  ```bash
  python3 /var/www/html/projetos/ai-dev/sync_openai_storage.py
  ```
- **Requisito:** `OPENAI_API_KEY` configurada no `.env` do Core.

---

## 8. Monitoramento e Manutenção

### 8.1. Logs de IA e Proxies
- **Gemini Proxy:** `/var/www/html/projetos/ai-dev/storage/logs/gemini-proxy.log` (ou log direto do script)
- **Claude Proxy:** `/var/www/html/projetos/ai-dev/storage/logs/claude-proxy.log`
- **Laravel AI:** `storage/logs/laravel.log` (procurar por `FailoverProvider`)

### 8.2. Reinício de Serviços
Após qualquer mudança em `config/ai.php` ou nos Proxies:
```bash
# Reiniciar Horizon e Workers
php artisan horizon:terminate

# Limpar caches
php artisan config:clear
php artisan cache:clear
```

---

**Nota Final:** Todo o sistema agêntico usa `openrouter` com família Anthropic (Opus 4.7 / Sonnet 4.6 / Haiku 4.5). Único `.env` necessário: `OPENROUTER_API_KEY`. Os proxies locais (8001/8002) são infraestrutura reservada para uso futuro.

