# Core Master (Módulos Padrão AndradeItalo.ai)

Este documento define a arquitetura dos módulos e submódulos fundamentais que devem ser incorporados em **todos** os novos sistemas desenvolvidos pela AndradeItalo.ai. Eles formam a base estrutural de Segurança, Auditoria e Manutenção.

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
Ao iniciar um novo projeto, a IA deve ser instruída a pré-carregar automaticamente esses módulos na Especificação Técnica, marcando a maioria como "Dependencies" (Dependências) para os módulos específicos de negócio.
