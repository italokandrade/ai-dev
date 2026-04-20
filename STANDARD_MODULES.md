# Core Master (Módulos Padrão AndradeItalo.ai)

Este documento define a arquitetura dos módulos e submódulos fundamentais que devem ser incorporados em **todos os novos Projetos Alvo** desenvolvidos pela AndradeItalo.ai — ou seja, as aplicações Laravel que o ai-dev-core vai orquestrar. Estes módulos vivem **no codebase do Projeto Alvo** (não no ai-dev-core) e formam a base estrutural de Segurança, Auditoria e Manutenção **daquele** projeto.

> O ai-dev-core, sendo também uma aplicação Laravel, já tem sua própria versão dessas áreas (Filament Shield, Horizon, etc.) provisionada internamente — mas o conteúdo deste documento trata do que é **injetado em cada Projeto Alvo** pelo `instalar_projeto.sh`. Para a separação canônica entre ai-dev-core e Projeto Alvo, consulte `README.md → Arquitetura em Duas Camadas`.

## 1. Módulo: Segurança & Autenticação (Security Core)
*Responsável pelo controle de fronteira do sistema.*
- **Submódulos:**
  - **Autenticação:** Login, Registro (quando aplicável), Recuperação de Senha, Autenticação de 2 Fatores (2FA).
  - **Controle de Acesso (ACL):** Criação e atribuição de Perfis (Roles) e Permissões (Permissions) granulares por tela/ação.
  - **Sessões Ativas:** Visualização de dispositivos logados com capacidade de derrubar sessões remotamente (útil em caso de roubo de dispositivos).
  - **Prevenção de Intrusão:** Bloqueio temporário de IPs por tentativas falhas (Rate Limiting).

## 2. Módulo: Auditoria & Compliance (Audit)
*O "caminho de migalhas" para resolver problemas sem o cliente perceber.*
- **Submódulos:**
  - **Log de Ações (Activity Log):** Rastreio de "Quem" fez "O Quê", "Quando", "Onde" (Tabela/Módulo) e com qual "IP". Registra o estado *Antes* e *Depois* das edições críticas.
  - **Histórico de Autenticação:** Registro de logins bem-sucedidos e falhos (com localização/IP).

## 3. Módulo: Suporte VIP (Developer Tools)
*Acesso restrito **exclusivo** para os engenheiros da AndradeItalo.ai operarem manutenções sem acessar o servidor físico.*
- **Submódulos:**
  - **Impersonação (Login-as):** Capacidade de um Admin da AndradeItalo "logar como" o cliente para debugar problemas na conta dele, sem pedir a senha.
  - **System Logs UI:** Visualizador do `laravel.log` direto no painel Filament, agrupado por data e severidade (Error, Warning, Info).
  - **Cache Manager:** Botões rápidos para limpar cache de views, configurações e banco de dados.
  - **Monitor de Filas (Jobs):** Dashboard estilo *Laravel Horizon* para ver se há e-mails parados ou tarefas em background falhando.
  - **Backups:** Painel para gerar, baixar e restaurar backups do banco de dados PostgreSQL.

## 4. Módulo: Configurações do Sistema (System Settings)
*Evita que configurações fiquem engessadas no código (hardcoded).*
- **Submódulos:**
  - **Identidade:** Alteração do Nome do Sistema, Logo, Favicon e Cores primárias do painel.
  - **Modo de Manutenção:** Ativa/desativa o modo "Em Manutenção" com tela de aviso para os clientes, mas permitindo o acesso de IPs da AndradeItalo.ai por meio de uma secret url.
  - **Variáveis de Ambiente (Safe Env):** Interface para alterar credenciais de APIs (Tokens de IA, SMTP, Gateways de Pagamento) sem precisar editar o `.env` via terminal.

## 5. Módulo: Gestão de Pessoas (User Management)
*A base de operação de qualquer software SaaS ou ERP.*
- **Submódulos:**
  - **Usuários:** Tabela de CRUD padrão de usuários.
  - **Convites:** Envio de links temporários para que membros da equipe do cliente criem suas próprias contas com segurança.

## 6. Módulo: Mensageria Core (Base Communication)
*Garante a comunicação do sistema.*
- **Submódulos:**
  - **Templates de E-mail:** Edição visual dos e-mails padrão do sistema (ex: "Bem-vindo", "Recuperação de senha").
  - **Caixa de Saída:** Rastreio de todos os e-mails enviados pelo sistema para debugar se o cliente "não recebeu".

---
**Regra de Implementação (AI-Dev):**
TODOS OS PROJETOS (inclusive o projeto Master `ai-dev-core`) devem obrigatoriamente ter este **Módulo de Gerenciamento/Admin Padrão**, garantindo que nunca comecem do zero absoluto. 

Estes submódulos (Cadastro de Perfis de Usuários, Usuários, Auditoria, Segurança Completa, etc.) **NÃO serão desenvolvidos ou reescritos pela Inteligência Artificial a cada projeto**. Eles formam um "Core Master" pré-fabricado (arquivos, configurações e dumps SQL). 

Durante a criação do projeto via `instalar_projeto.sh`, todo o código-fonte e o banco de dados base destes módulos são **copiados** para dentro dos projetos alvo. Uma vez copiados, os agentes de desenvolvimento **NÃO PODEM alterá-los** (a menos que o modelo padrão no repositório Master seja alterado para replicar nos demais). Assim, o painel administrativo nasce 100% estruturado e blindado.

Nas permissões de acesso (Controle de Acesso), elas devem estar rigorosamente vinculadas aos Perfis de Usuários. Para cada perfil, o painel lista todos os módulos do sistema e permite definir individualmente quais funções do "CRUD" aquele perfil está autorizado a acessar ou manipular.

**Atenção sobre as Credenciais de IA:** 
Como os projetos alvo são desenvolvidos pelo `ai-dev-core`, as credenciais de desenvolvimento da IA operária (via OpenRouter) pertencem apenas ao `ai-dev-core` (no `.env` do servidor). Porém, a credencial e o modelo utilizados pelas *IAs de interação e módulos internos* do projeto alvo (e também dentro do próprio `ai-dev-core` para os seus assistants) devem ser cadastrados no painel administrativo via módulo de **Configurações de IA**, evitando hardcoding em `.env`.

**Além do Core Master, `instalar_projeto.sh` também instala o Laravel AI SDK e o Laravel Boost no Projeto Alvo.** O AI SDK serve às **IAs de interação do próprio projeto**. O Boost serve de **fonte de contexto** consumida pelos agentes de desenvolvimento do ai-dev-core via `BoostTool` durante a execução de tasks. **Nenhum agente de desenvolvimento (`Orchestrator`, `Specialist`, `QAAuditor`, `DocsAgent`) é instalado no Projeto Alvo** — esses são exclusivos do ai-dev-core.

A IA de desenvolvimento do ai-dev-core atuará apenas integrando e marcando esses módulos preexistentes como "Dependencies" (Dependências) para os módulos específicos de negócio que ela de fato irá codificar.
