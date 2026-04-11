# Guia do Painel Administrativo AI-Dev (Filament v5)

Este documento descreve o funcionamento do ambiente web de gestão do ecossistema AI-Dev, localizado em `/ai-dev-core/public/admin`.

## 1. Gestão de Projetos (`Projects`)
O ponto de partida para qualquer automação. Cada projeto representa uma aplicação distinta no servidor.
- **Provedor e Modelo:** Define qual IA (Gemini, Claude, Ollama) será o "cérebro" padrão do projeto.
- **Contexto Persistente:** O sistema armazena IDs de sessão para que a IA mantenha a memória de longo prazo sobre o projeto.

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
As tarefas são as unidades de trabalho executadas pelos agentes.

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
O AI-Dev utiliza um sistema de **Context Awareness** (Ciência de Contexto) em tempo real.
- **Detecção Automática:** O sistema varre o servidor para detectar a versão do OS, PHP, PostgreSQL e extensões (como `pgvector`).
- **Sincronização da Stack:** As versões exatas do Laravel, Filament, Tailwind e Anime.js são injetadas no prompt da IA.
- **Benefício:** A IA nunca sugerirá uma tecnologia ou sintaxe que não seja compatível com a versão física instalada no seu servidor.

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
- **Causa:** Chaves API (`OPENAI_API_KEY`) e modelos (`gpt-5-nano`) não são versionados por segurança.
- **Solução:** Verifique o arquivo `.env` local. O sistema está pré-configurado para usar OpenAI como provedor padrão para refinamento de descrição.
