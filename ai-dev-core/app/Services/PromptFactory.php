<?php

namespace App\Services;

use App\Models\AgentConfig;
use App\Models\Project;
use App\Models\Subtask;
use App\Models\Task;
use App\Tools\ToolRouter;

class PromptFactory
{
    /**
     * Universal execution discipline rules (Layer 1).
     */
    private const UNIVERSAL_RULES = <<<'RULES'
## Regras Universais de Execução

1. **Ação, não intenção:** Você DEVE usar suas ferramentas para agir — não descreva o que você "faria" sem agir.
2. **Sem promessas:** Quando disser que vai fazer algo, execute a chamada da ferramenta IMEDIATAMENTE.
3. **Trabalho contínuo:** Continue trabalhando até a tarefa estar REALMENTE completa.
4. **Verificação obrigatória:** Antes de reportar como concluída, verifique: corretude, grounding (leu o código real?), testes, sintaxe.
5. **Paralelismo:** Se precisa ler 5 arquivos, chame FileTool 5 vezes em paralelo.
6. **Economia:** Prefira FileTool.action="patch" em vez de "write". Especifique start_line/end_line no read.
RULES;

    private const PROVIDER_HINTS = [
        'gemini' => <<<'HINT'
## Instruções Específicas (Gemini)
- Sempre use caminhos ABSOLUTOS (iniciando em /var/www/html/projetos/).
- Verifique ANTES de sobrescrever: use FileTool.action="read" antes de "write".
- Use flags --no-interaction, -y, --force em comandos ShellTool.
- Maximize paralelismo de tool calls.
HINT,
        'anthropic' => <<<'HINT'
## Instruções Específicas (Claude)
- Não pare precocemente — continue até o resultado ser PERFEITO.
- Se uma ferramenta retornar vazia, tente com outra estratégia antes de desistir.
- Organize seus pensamentos passo a passo antes de gerar Sub-PRDs ou código.
HINT,
        'ollama' => <<<'HINT'
## Instruções (Ollama Local)
- Sem tool calls. Recebe texto, retorna texto.
- Tarefa única: resumir mantendo TODOS os detalhes técnicos.
HINT,
    ];

    public function __construct(
        private ToolRouter $toolRouter,
    ) {}

    /**
     * Build the complete system prompt for an agent.
     */
    public function buildSystemPrompt(AgentConfig $agent, ?Project $project = null): string
    {
        $parts = [];

        // Layer 1: Universal rules
        $parts[] = self::UNIVERSAL_RULES;

        // Layer 2: Provider-specific hints
        $provider = $agent->provider->value;
        if (isset(self::PROVIDER_HINTS[$provider])) {
            $parts[] = self::PROVIDER_HINTS[$provider];
        }

        // Layer 3: Agent role description
        $parts[] = "## Seu Papel\n\n" . $agent->role_description;

        // Layer 4: Tools manifest (only for tool-capable agents)
        if ($provider !== 'ollama') {
            $tools = $this->toolRouter->getToolsManifest();
            if (! empty($tools)) {
                $toolsJson = json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $parts[] = "## Ferramentas Disponíveis\n\nVocê tem acesso às seguintes ferramentas. Para usá-las, retorne um JSON com `tool_name` e `parameters`.\n\n```json\n{$toolsJson}\n```";
            }
        }

        // Layer 5: Project context
        if ($project) {
            $parts[] = "## Projeto Ativo\n\n- **Nome:** {$project->name}\n- **Caminho:** {$project->local_path}\n- **GitHub:** {$project->github_repo}";
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Build the user message for the Orchestrator containing the PRD.
     */
    public function buildOrchestratorMessage(Task $task): string
    {
        $prdJson = json_encode($task->prd_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<MSG
## Nova Task para Decomposição

**Task ID:** {$task->id}
**Título:** {$task->title}
**Prioridade:** {$task->priority}

### PRD Completo:

```json
{$prdJson}
```

Decomponha este PRD em Sub-PRDs atômicos, cada um destinado a um subagente especialista.
Retorne um JSON array com os Sub-PRDs.
MSG;
    }

    /**
     * Build the user message for a Subagent executor with its Sub-PRD.
     */
    public function buildSubagentMessage(Subtask $subtask): string
    {
        $subPrdJson = json_encode($subtask->sub_prd_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<MSG
## Sub-PRD para Execução

**Subtask ID:** {$subtask->id}
**Título:** {$subtask->title}
**Ordem de Execução:** {$subtask->execution_order}

### Sub-PRD:

```json
{$subPrdJson}
```

Execute TODOS os itens deste Sub-PRD usando as ferramentas disponíveis. Verifique cada resultado antes de prosseguir.
MSG;
    }

    /**
     * Build the user message for the QA Auditor.
     */
    public function buildQAAuditMessage(Subtask $subtask): string
    {
        $subPrdJson = json_encode($subtask->sub_prd_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<MSG
## Auditoria de Qualidade

**Subtask ID:** {$subtask->id}
**Título:** {$subtask->title}

### Sub-PRD Original (o que foi PEDIDO):

```json
{$subPrdJson}
```

### Git Diff (o que foi FEITO):

```diff
{$subtask->result_diff}
```

### Logs de Execução:

```
{$subtask->result_log}
```

### Arquivos Modificados:
{$this->formatFilesList($subtask->files_modified)}

Audite a entrega contra CADA critério do Sub-PRD. Retorne o JSON de auditoria.
MSG;
    }

    private function formatFilesList(?array $files): string
    {
        if (empty($files)) {
            return '- Nenhum arquivo modificado reportado';
        }

        return implode("\n", array_map(fn ($f) => "- `{$f}`", $files));
    }
}
