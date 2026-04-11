# AI-Dev Core (Painel de Gestão)

Este é o coração do ecossistema AI-Dev, desenvolvido em **Laravel 13** com **Filament v5**. Ele atua como o cérebro central e interface de comando para a orquestração de agentes.

## 🚀 Tecnologias
- **PHP 8.4**
- **Filament v5** (Web UI)
- **Laravel AI SDK** (Integração com LLMs)
- **PostgreSQL 16** (Estado Central)

## 🛠️ Componentes Principais (Filament Resources)

### [Módulos (ProjectModuleResource)](app/Filament/Resources/ProjectModuleResource.php)
Gerencia a decomposição dos projetos em partes menores.
- **Hierarquia:** Suporta módulos pai e filhos para refinamento de contexto.
- **Dependências:** Filtro inteligente que permite apenas dependências de módulos já concluídos.
- **Prioridade:** Classificação semântica (Padrão, Média, Alta) em vez de numérica.

### [Tasks (TaskResource)](app/Filament/Resources/TaskResource.php)
Unidade fundamental de trabalho dos agentes.
- **PRD Automático:** Armazena o payload JSON com objetivos e critérios de aceite.
- **Máquina de Estados:** Controla o ciclo de vida da tarefa desde a pendência até o deploy.

### [Agentes (AgentConfigResource)](app/Filament/Resources/AgentConfigResource.php)
Configuração individual de cada especialista.
- Definição de `system_prompt`, `model` e `temperature`.
- Limites de retentativas e custos por execução.

### [Orçamentos (ProjectQuotationResource)](app/Filament/Resources/ProjectQuotationResource.php)
Ferramenta de cálculo de viabilidade econômica.
- Compara custo humano (Senior/h) vs. custo AI-Dev.
- Rastreia consumo real de tokens via `agent_executions`.

## 📂 Estrutura de Pastas Relevante
- `app/Filament/Resources/`: Definições da interface administrativa.
- `app/Models/`: Modelagem do banco de dados com lógica de transições.
- `app/Enums/`: Enums estritos para status e prioridades.
- `database/migrations/`: Evolução do schema (incluindo simplificação de prioridade e hierarquia).

---
Para documentação de arquitetura global e ferramentas, consulte o [README da raiz](../README.md).
