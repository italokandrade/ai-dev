# Catálogo Exaustivo de Ferramentas (The Tool Layer)

O AI-Dev adota o **Padrão de Injeção de Comandos (Command-Injection Pattern)**. O ecossistema é composto por um arsenal de ferramentas pré-construídas no servidor que cobrem 100% das necessidades de um desenvolvedor Fullstack TALL + DBA. A IA gera apenas os **parâmetros e dados brutos** para injeção.

---

## 1. Gestão Total de Banco de Dados (DBA Power Tools)

Ferramentas para manipulação de estrutura (DDL) e dados (DML), garantindo integridade e velocidade.

*   **`SchemaManagerTool` (Estrutura):**
    *   *Ações:* Criar/Alterar/Remover tabelas, colunas, índices, chaves estrangeiras e relacionamentos.
    *   *Uso:* A IA envia o blueprint JSON; a ferramenta gera e executa a Migration ou SQL equivalente.
*   **`DataManipulatorTool` (Dados):**
    *   *Ações:* `SELECT` complexos, `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`.
    *   *Uso:* Listagem de registros com paginação e filtros avançados para conferência de regras de negócio.
*   **`DatabaseMaintenanceTool` (Manutenção):**
    *   *Ações:* `DUMP` completo ou parcial, `RESTORE`, `OPTIMIZE` tabelas, verificar integridade de índices e monitorar tamanho do banco.
*   **`SeederGeneratorTool`:**
    *   *Ações:* Popular tabelas com dados fake realistas injetados via IA, garantindo que o sistema web nunca pareça vazio no desenvolvimento.

---

## 2. Manipulação de Arquivos e Infraestrutura de Diretórios

Ferramentas para gestão do sistema de arquivos com foco em segurança e permissões.

*   **`FileArchitectTool` (Gestão de Estrutura):**
    *   *Ações:* Criar diretórios, renomear arquivos/pastas, mover arquivos (Refactor move) e deletar (com backup automático).
*   **`PermissionGuardTool` (Segurança):**
    *   *Ações:* Ajustar `chmod` e `chown` exclusivamente para os padrões do servidor (www-data), garantindo que a aplicação Laravel tenha escrita em `storage/` e `bootstrap/cache/` sem comprometer o root do servidor.
*   **`FileSurgeryTool` (Edição de Alta Precisão):**
    *   *Ações:* Search & Replace por linha, inserção de métodos em classes existentes (Regex-based), remoção de blocos de código obsoletos sem reescrever o arquivo.

---

## 3. Ecossistema Laravel, Filament e TALL (Artisan & Assets)

*   **`ArtisanPowerTool`:**
    *   *Ações:* Execução de todos os comandos `php artisan`.
    *   *Específicos:* `make:filament-resource`, `filament:install`, `make:livewire`, `make:model`, `clear-compiled`, `route:list`.
*   **`AssetCompilerTool`:**
    *   *Ações:* `npm install`, `npm run build`, `vite build`. Gestão automatizada do Tailwind CSS para compilar novas classes geradas pela IA.
*   **`AnimeJsIntegratorTool`:**
    *   *Ações:* Injeção de scripts de animação Anime.js diretamente no Blade/Alpine, seguindo o padrão de injeção global (`window.anime`) definido nas instruções do servidor.

---

## 4. Controle de Versão e Deployment (GitFlow)

*   **`GitMasterTool`:**
    *   *Ações:* `init`, `add`, `commit`, `pull`, `push`, `branch` (create/switch), `merge`, `stash`, `revert` e `diff`.
    *   *Inteligência:* A ferramenta gera o sumário do commit baseado no PRD executado.

---

## 5. Pesquisa, Scraping e Visão (Intelligence Tools)

*   **`FirecrawlScraperTool` (Nativo/Self-Hosted):** Raspagem de docs para Markdown.
*   **`VisionBrowserTool`:** Screenshots de falhas de UI para análise multimodal.
*   **`DuckDuckGoSearchTool`:** Pesquisa externa por soluções técnicas.

---

## 6. Meta-Evolução e Fallback

*   **`ToolCreatorTool`:** Criação de novas ferramentas permanentes para usos recorrentes não mapeados.
*   **`FailSafeLogger`:** Registro obrigatório de impossibilidades técnicas nas observações do banco de dados MariaDB para tratamento manual.

---
**Nota de Segurança:** Todas as ferramentas de manipulação de arquivo e banco de dados operam sob logs de auditoria e caminhos absolutos para prevenir qualquer escape do diretório do projeto.
