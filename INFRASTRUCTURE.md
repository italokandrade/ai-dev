# Requisitos de Infraestrutura (AI-Dev)

O AI-Dev (AndradeItalo.ai) foi projetado para ser **à prova de falhas (fault-tolerant)** e **extremamente veloz**. Esta documentação mapeia a infraestrutura atualmente disponível no servidor Supreme (10.1.1.86) e define os componentes exatos que precisarão ser instalados para garantir o cumprimento desses requisitos.

---

## 1. O que JÁ TEMOS no Servidor (Pronto para Uso)

A infraestrutura base já é de alto nível e atende perfeitamente ao ecossistema TALL:

| Componente | Versão | Papel no AI-Dev |
|---|---|---|
| **Ubuntu 24.04 LTS** | 24.04 | Sistema operacional do servidor (2 vCPUs, 8 GB RAM) |
| **MariaDB** | 10.x+ | Banco de dados relacional CORE — tabelas de projetos, tasks, subtasks, logs |
| **Redis Server** | 7.0 | Barramento de eventos, filas de execução (Laravel Queue), cache, Pub/Sub |
| **PHP** | 8.3.30 | Runtime do Laravel 12 — onde roda o AI-Dev Core |
| **Node.js** | 22.x | Runtime para npm scripts, compilação de assets (Vite/Tailwind) |
| **Bun** | Latest | Runtime alternativo (pode ser usado para scripts de build mais rápidos) |
| **Python** | 3.12 (venv) | Runtime para ChromaDB (banco vetorial) e scripts auxiliares |
| **Laravel** | 12.x | Framework base do AI-Dev Core |
| **Livewire** | 4.x | Interatividade real-time na Web UI |
| **Filament** | v5 | Painel administrativo — Dashboard, Resources, gestão de projetos e tasks |
| **Anime.js** | Latest | Animações na UI do Filament |
| **PHPUnit/Pest** | Latest | Testes automatizados dos projetos alvo |
| **Laravel Dusk** | Latest | Testes de browser automatizados |
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
| Gemini Proxy | `/etc/supervisor/conf.d/gemini-proxy.conf` | 1 | N/A (porta 8000) |

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

### 2.2. Motores LLM (Proxy Gemini, Claude Code e Ollama)

Para o AI-Dev operar com redundância e inteligência de elite, precisamos de 3 motores:

#### Motor 1: Proxy Gemini (O Executor Principal)

**Status:** ✅ Já existe parcialmente (portas 8000/8001, `gemini_proxy.js`/`gemini_proxy.py`).

**O que precisa ser feito:**
- Garantir que o proxy aceita o parâmetro `session_id` para contexto persistente por projeto
- Mover o gerenciamento do processo do `nohup` para o Supervisor (config acima)
- Validar que o modelo `gemini-3.1-flash` está funcionando
- Criar endpoint de health check (`/health`) para o dashboard do AI-Dev monitorar

**Custo estimado:** Gemini 3.1 Flash é gratuito até um limite generoso. Para uso pesado, o custo é ~$0.075/1M tokens de entrada e ~$0.30/1M tokens de saída.

#### Motor 2: Claude Code (O Cérebro de Elite)

**Status:** ❌ Precisa ser instalado.

**O que é:** O CLI oficial da Anthropic (@anthropic-ai/claude-code) que permite invocar Claude via terminal.

**Por que usar Claude e não apenas Gemini?** Claude Sonnet 4.6 e Opus 4.6 demonstram raciocínio mais rigoroso em tarefas de planejamento (quebra de PRDs) e auditoria (validação de código). O AI-Dev usa Claude para o Orchestrator e o QA Auditor — as funções que exigem "pensamento" mais que "ação".

**Instalação:**
```bash
# 1. Instalar o CLI global via npm
npm install -g @anthropic-ai/claude-code

# 2. Configurar a API key
export ANTHROPIC_API_KEY="sk-ant-api03-..."

# 3. Adicionar ao .env do AI-Dev Core
echo "ANTHROPIC_API_KEY=sk-ant-api03-..." >> /var/www/html/projetos/ai-dev-core/.env

# 4. Verificar
claude --version
```

**Integração no AI-Dev:** O `LLMGateway.php` chamará Claude via:
- **API HTTP direta** (https://api.anthropic.com/v1/messages) — preferível para integração programática
- **CLI** como fallback — `claude -p "prompt" --output-format json`

**Custo estimado:** Claude Sonnet 4.6: ~$3/1M input + $15/1M output. Claude Opus 4.6: ~$15/1M input + $75/1M output. O Orchestrator e QA usam poucas chamadas (1-3 por task), então o custo por task é ~$0.05-0.20.

#### Motor 3: Ollama (O Compressor de Memória)

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

**Consumo de RAM estimado:** ~850 MB com ambos os modelos carregados. O servidor com 8 GB suporta tranquilamente (PHP + MariaDB + Redis usam ~2-3 GB, sobrando ~4-5 GB).

---

### 2.3. Banco de Dados Vetorial (Memória de Longo Prazo e RAG)

**O problema:** O MariaDB é excelente para relações e queries estruturadas, mas NÃO é otimizado para busca semântica vetorial (encontrar "textos similares" baseado em significado, não em palavras exatas).

**A solução:** Precisamos de um banco vetorial para a funcionalidade de RAG — resgatar soluções passadas e injetar padrões few-shot no prompt.

**Duas opções (escolher UMA):**

#### Opção A: ChromaDB (Recomendada para escala)

**O que é:** Banco de dados vetorial open-source que roda como serviço Python. Leve, rápido, e com API REST simples.

**Instalação:**
```bash
# 1. Ativar o venv Python
source /root/venv/bin/activate

# 2. Instalar ChromaDB
pip install chromadb

# 3. Iniciar como serviço (porta 8899)
chroma run --host 0.0.0.0 --port 8899 --path /var/www/html/projetos/ai-dev-core/storage/chroma

# 4. Adicionar ao Supervisor para rodar permanentemente
# /etc/supervisor/conf.d/chroma.conf
```

**Integração no AI-Dev:** O `LLMGateway.php` chama a API do ChromaDB via HTTP:
```
POST http://localhost:8899/api/v1/collections/problems_solutions/query
{
  "query_embeddings": [[0.1, 0.2, ...]],  // Embedding do PRD atual
  "n_results": 3                           // Top 3 mais similares
}
```

#### Opção B: SQLite-Vec (Recomendada para simplicidade)

**O que é:** Extensão do SQLite que adiciona suporte a vetores. Zero dependência extra — usa o SQLite que já vem com o PHP.

**Instalação:**
```bash
# 1. Compilar a extensão sqlite-vec
cd /tmp
git clone https://github.com/asg017/sqlite-vec.git
cd sqlite-vec
make
sudo cp dist/vec0.so /usr/lib/sqlite3/

# 2. Habilitar no PHP
echo "extension=vec0.so" >> /etc/php/8.3/cli/php.ini
```

**Prós e contras:**

| | ChromaDB | SQLite-Vec |
|---|---|---|
| **Facilidade** | Simples (pip install) | Requer compilação |
| **Performance** | Excelente para milhares de vetores | Boa para centenas |
| **Dependência** | Python 3 (já temos) | Nenhuma extra |
| **API** | REST (HTTP) | SQL nativo |
| **Escala** | Até milhões de vetores | Até dezenas de milhares |
| **RAM** | ~200 MB | ~50 MB |

**Recomendação:** Começar com **ChromaDB** pela simplicidade de instalação e API limpa. Se o consumo de RAM se tornar problema no futuro, migrar para SQLite-Vec.

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

**O que é:** O projeto Laravel 12 dedicado que atua como o "cérebro" do sistema. Contém os Jobs (Orchestrator, Subagentes, QA), os Models, as Migrations, a Prompt Factory, o Tool Router, e o Painel Filament.

**Criação:**
```bash
# 1. Gerar o projeto Laravel 12
cd /var/www/html/projetos
laravel new ai-dev-core

# 2. Instalar Filament v5
cd ai-dev-core
composer require filament/filament:"^5.0"
php artisan filament:install --panels

# 3. Instalar Laravel Horizon (filas Redis)
composer require laravel/horizon
php artisan horizon:install

# 4. Configurar .env
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_DATABASE=ai_dev_core
# DB_USERNAME=root
# DB_PASSWORD=***
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1

# 5. Criar o banco
mysql -u root -p -e "CREATE DATABASE ai_dev_core CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Rodar migrations
php artisan migrate

# 7. Criar usuário admin do Filament
php artisan make:filament-user

# 8. Permissões
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 9. Iniciar
php artisan serve --host=0.0.0.0 --port=8080
```

**Estrutura de diretórios resultante:** Ver `ARCHITECTURE.md` seção 3.3 para o mapa completo de classes.

---

## 3. Consumo de Recursos Estimado (O Servidor Aguenta?)

Com tudo instalado e rodando simultaneamente:

| Componente | RAM Estimada | CPU | Disco |
|---|---|---|---|
| Ubuntu 24.04 + PHP-FPM | ~500 MB | Baixo | N/A |
| MariaDB | ~400 MB | Moderado | ~500 MB (dados) |
| Redis 7 | ~200 MB | Baixo | ~100 MB |
| Laravel (AI-Dev Core) | ~150 MB | Moderado | ~200 MB |
| Laravel Queue Workers (9x) | ~900 MB | Variável | N/A |
| Ollama (2 modelos) | ~850 MB | Pico durante inferência | ~700 MB |
| ChromaDB | ~200 MB | Baixo | ~100 MB |
| Node.js (Firecrawl/Proxy) | ~300 MB | Baixo | ~200 MB |
| Security Tools (sob demanda) | ~100 MB | Pico durante scan | ~50 MB |
| **TOTAL** | **~3.6 GB** | **Variável** | **~1.85 GB** |

**Veredicto:** O servidor com **8 GB de RAM** suporta confortavelmente toda a stack com ~4.4 GB de folga para picos. As ferramentas de segurança (Enlightn, PHPStan, Nikto, SQLMap) rodam sob demanda e encerram ao finalizar, então o consumo real é intermitente. As 2 vCPUs são o gargalo principal — inferência LLM local (Ollama) consome CPU durante execução, mas o modelo é tão leve (0.5B params) que infere em ~1-2 segundos.

**Recomendação de escala futura:** Se o sistema crescer para 5+ projetos simultâneos com 10+ tasks em paralelo, considerar upgrade para 4 vCPUs e 16 GB RAM.

---

## 4. Resumo de Ação por Fase (Alinhado com ARCHITECTURE.md seção 10)

### Fase 1: Core Loop (NÃO precisa de Ollama, ChromaDB nem Firecrawl)

```bash
# Apenas o essencial para o ciclo Task → Orchestrator → Subagente → QA → Commit
1. sudo apt install supervisor
2. laravel new ai-dev-core (no servidor)
3. Instalar Filament v5 + Horizon
4. Criar banco ai_dev_core + rodar migrations
5. Configurar Supervisor para workers (orchestrator, executors, qa)
6. Migrar Gemini Proxy do nohup para Supervisor
7. Testar ciclo completo com 1 task simples
```

### Fase 2: Qualidade, Segurança e UI (Adiciona Claude + Sentinel + Security Tools)

```bash
# Adiciona capacidade de auditoria de segurança e performance
1. npm install -g @anthropic-ai/claude-code
2. Configurar ANTHROPIC_API_KEY no .env
3. Instalar ferramentas de segurança no servidor:
   sudo apt install nikto -y
   source /root/venv/bin/activate && pip install sqlmap
4. Instalar Enlightn + Larastan nos projetos alvo:
   composer require enlightn/enlightn larastan/larastan --dev
5. Implementar SecurityAuditJob + PerformanceAnalysisJob
6. Configurar Supervisor para workers de segurança e performance
7. Implementar Filament Resources + Dashboard
8. Criar Sentinel (Exception Handler nos projetos alvo)
9. Configurar circuit breakers
```

### Fase 3: IA Avançada (Adiciona Ollama + ChromaDB + Firecrawl)

```bash
1. curl -fsSL https://ollama.com/install.sh | sh
2. ollama pull qwen2.5:0.5b && ollama pull nomic-embed-text
3. pip install chromadb (no venv Python)
4. Instalar Firecrawl ou readability-cli
5. Implementar RAG + Compressão de Contexto
6. (Opcional) Instalar OWASP ZAP para scan DAST avançado
7. Configurar Supervisor para Ollama e ChromaDB
```

---

**Nota Final:** Cada componente é instalado APENAS quando sua fase correspondente chegar. Isso evita complexidade desnecessária no início e garante que o sistema funcione em cada etapa incremental.
