# Guia do Painel Administrativo AI-Dev (Filament v5)

Este documento descreve o funcionamento do **Admin Panel do ai-dev-core** (Master), localizado em `/ai-dev-core/public/admin`. É daqui que o humano cadastra Projetos Alvo, cria tasks, dispara cotações e acompanha execução dos agentes.

> **Dois Admin Panels coexistem no ecossistema — não confundir:**
> - **Admin Panel do ai-dev-core** *(este guia)* — gere `projects` (cadastro dos alvos), `tasks`, `subtasks`, `agents_config`, `project_quotations`. É aqui que as IAs de interação do Master (`RefineDescriptionAgent`, `SpecificationAgent`, `QuotationAgent`) falam com o usuário para estruturar o PRD antes da execução.
> - **Admin Panel de cada Projeto Alvo** *(documentado no repositório de cada alvo, não aqui)* — gere as entidades de negócio daquele projeto (ex: clientes, pedidos, usuários finais) e hospeda as IAs de interação específicas daquele projeto (copiloto do usuário, classificador, etc.). O ai-dev-core **não** opera este painel — ele apenas gera o código dele.
>
> Para a tabela canônica de separação entre ai-dev-core e Projeto Alvo, veja `README.md → Arquitetura em Duas Camadas`.

## 1. Gestão de Projetos (`Projects`)
O ponto de partida para qualquer automação. Cada linha em `projects` representa um **Projeto Alvo** — uma aplicação Laravel externa, com repositório próprio, banco próprio e Boost MCP próprio, que o ai-dev-core vai desenvolver/refatorar/manter.

- **Campos-chave:** `name`, `github_repo`, `local_path` (caminho absoluto no servidor, ex: `/var/www/html/projetos/portal`), `status`. O `local_path` é o **ponto de acoplamento**: todo `FileReadTool`/`FileWriteTool`/`ShellExecuteTool`/`GitOperationTool`/`BoostTool` usado pelos agentes recebe esse path e opera exclusivamente dentro dele.
- **Provedor e Modelo:** Todo o sistema agêntico do ai-dev-core usa o provider `openrouter` com família Anthropic — `claude-opus-4.7` (planejamento), `claude-sonnet-4-6` (código/QA), `claude-haiku-4-5-20251001` (docs). Configurado em `config/ai.php` **do ai-dev-core**. Cada Projeto Alvo tem seu próprio `.env` e sua própria `OPENROUTER_API_KEY` para as IAs de interação que rodam dentro dele — independentes deste cadastro.
- **Contexto Persistente:** O ai-dev-core armazena o ID de sessão (coluna `anthropic_session_id`) e as conversas (tabelas `agent_conversations`, `agent_conversation_messages`) **no banco do ai-dev-core** — nenhum dado desse tipo contamina o banco do alvo.

## 2. Estrutura de Módulos (`Modules`)
Os módulos permitem decompor um projeto complexo em partes menores e gerenciáveis.

### 2.1 Hierarquia (Módulos e Submódulos)
- O sistema suporta **hierarquia infinita**. Um módulo pode ter um "Módulo Pai".
- **Exemplo:** `Mensageria` > `WhatsApp` > `Caixa de Entrada`.
- Isso permite que os agentes recebam contextos extremamente refinados, focando apenas na "folha" da árvore onde a tarefa deve ser executada.

### 2.2 Dependências Estritamente Consolidadas
- Um módulo pode depender de outros módulos do mesmo projeto.
- **Regra de Seleção:** O sistema só permite selecionar como dependência módulos que já estejam com o status **Concluído** (`Completed`).
- Isso garante que a base de código onde o novo módulo será construído está estável e testada.

## 3. Gestão de Tarefas (`Tasks`)
As tarefas são as unidades de trabalho executadas pelos **agentes de desenvolvimento** do ai-dev-core. Cada task referencia um `project_id` — e todo o trabalho concreto (leitura de código, escrita, testes, commits) acontece no `local_path` daquele Projeto Alvo, **nunca** no filesystem do ai-dev-core.

### 3.1 Classificação de Prioridade
A prioridade não é mais um número confuso, mas sim uma classificação semântica:
- **Padrão (Normal):** Fluxo comum de desenvolvimento.
- **Média (Medium):** Tarefas com prazos moderados ou importância intermediária.
- **Alta (High):** Tarefas críticas ou bloqueantes.
- **Ordenação:** Internamente, o sistema processa primeiro as de maior prioridade seguindo a data de criação (mais antigas primeiro).

### 3.2 Vinculação e Fluxo
- Uma task pode ser avulsa ou vinculada a um **Módulo**.
- O fluxo segue uma máquina de estados rigorosa: `Pending` > `In Progress` > `Testing` > `Revision` > `Completed`/`Failed`.

## 4. Orçamentos e Custos (`Quotations`)
Localizado no grupo "Configuração", permite estimar o custo humano vs. custo AI-Dev.
- **Custo Real:** O sistema rastreia em tempo real o consumo de tokens (USD) e infraestrutura (BRL) de cada projeto, acumulando os valores na cotação ativa.

## 5. Áreas de Conhecimento
Ao criar módulos ou tarefas, você define quais especialistas serão convocados:
- `backend`, `frontend`, `database`, `filament`, `devops`, `testing`, `design`.

## 6. Inteligência Híbrida e Contexto Dinâmico
O AI-Dev utiliza um sistema de **Context Awareness** (Ciência de Contexto) em tempo real — lendo sempre do **Projeto Alvo**, não do ai-dev-core.
- **Detecção via Boost do alvo:** o `BoostTool` (a ser tornado project-path-aware — ver README, Fase 1) executa `php artisan boost:*` dentro do `local_path` do alvo e retorna schema, docs instaladas e estado do código daquele projeto.
- **Sincronização da Stack:** As versões exatas de Laravel, Filament, Livewire, Tailwind e Anime.js **instaladas no Projeto Alvo** são a fonte de verdade — não as versões do ai-dev-core. Projetos podem divergir em versão de pacote e cada um é tratado pelo seu próprio Boost.
- **Benefício:** A IA nunca sugerirá sintaxe de Filament v4 para um projeto com Filament v5, nem vice-versa — mesmo que o ai-dev-core esteja em versão diferente.

## 7. Animações com Anime.js
O ambiente web e os projetos gerados contam com a biblioteca **Anime.js v4** integrada nativamente.
- **Acesso Global:** Disponível via `window.anime` em qualquer componente Livewire ou Script Alpine.js.
- **Uso no Refinamento:** O Agente de IA está instruído a considerar o Anime.js para propostas de interfaces modernas e dinâmicas.

---
*Este manual reflete a versão 1.2 do sistema, com foco em ciência de contexto e interfaces ricas.*

## 🛠 Histórico de Ajustes e Solução de Problemas

Se você encontrar erros ao operar o sistema, consulte esta seção de lições aprendidas:

### 1. Erro de Prioridade (`ValueError`)
- **Problema:** "X" is not a valid backing value for enum `App\Enums\Priority`.
- **Causa:** O sistema foi migrado de prioridades numéricas (10, 50, 90) para um Enum de string (`normal`, `medium`, `high`). Registros antigos com números travam a renderização.
- **Solução:** O sistema agora faz a conversão automática, mas em novos ambientes, certifique-se de que a coluna `priority` na migration seja `string` com default `normal`.

### 2. Erro de Coluna Inexistente (`order`)
- **Problema:** SQLSTATE[42703]: Undefined column: 7 ERROR: column "order" does not exist.
- **Causa:** A coluna `order` foi removida para simplificar a gestão pelos agentes (que agora usam prioridade + data).
- **Solução:** Remova qualquer `orderBy('order')` de Models, Resources ou Widgets. Use `orderBy('created_at', 'desc')` ou ordene pelo Enum de prioridade.

### 3. Erro de Tipagem no Refinamento IA (`TypeError`)
- **Problema:** Argument #1 ($form) must be of type `Filament\Forms\ComponentContainer`, `Filament\Schemas\Schema` given.
- **Causa:** No Filament v5 com a stack de Schemas, o container de componentes mudou de tipo.
- **Solução:** No método `mountUsing` de Actions de formulário, use sempre `\Filament\Schemas\Schema $form`.

### 4. Configuração de IA e Chaves API
- **Problema:** IA não responde ou erro de autenticação.
- **Causa:** Chaves API não são versionadas por segurança.
- **Solução:** Verifique o arquivo `.env` local. Provider único: `OPENROUTER_API_KEY`. Todos os agentes usam OpenRouter com família Anthropic.
