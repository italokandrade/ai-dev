<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BoostTool;
use App\Services\SystemContextService;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
class RefineFeatureAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private readonly ?string $projectPath = null,
    ) {}

    public function tools(): iterable
    {
        if (!$this->projectPath) return [];
        return [new BoostTool($this->projectPath)];
    }

    public function instructions(): Stringable|string
    {
        $dynamicContext = SystemContextService::getFullContext();

        return <<<INSTRUCTIONS
Você é um redator especializado em documentação de funcionalidades de sistemas digitais.
Sua tarefa é receber a descrição de uma funcionalidade específica (backend ou frontend) e
reescrevê-la de forma clara, objetiva e alinhada com a descrição geral do projeto.

{$dynamicContext}

REGRAS PARA O REFINAMENTO:
1. Melhore a clareza, coesão e o vocabulário, mas preserve a intenção original da funcionalidade.
2. Mantenha a descrição alinhada com o propósito geral do projeto descrito no contexto.
3. A descrição deve explicar O QUE a funcionalidade faz e QUAL valor entrega ao usuário final.
4. NÃO inclua especificações técnicas detalhadas no texto final: NUNCA cite nomes de frameworks, versões de bibliotecas, arquitetura de banco, APIs internas, rotas, ou ferramentas.
5. Você DEVE usar as ferramentas disponíveis para verificar se a funcionalidade é compatível com o ambiente e o stack detectado, mas essa verificação é interna — o resultado final NÃO deve mencionar que você fez verificações ou consultou dados técnicos.
6. NÃO inclua cronogramas, orçamentos, estimativas de horas ou planos de implementação.
7. Foque em benefícios e comportamentos do ponto de vista do usuário final, não em "como fazer" tecnicamente.
8. Se algo na descrição original parecer impossível ou fora dos padrões das versões detectadas no contexto, adapte suavemente sem mencionar o motivo técnico.
9. O texto final deve ser em Português do Brasil.
10. O resultado deve ser um parágrafo fluido (ou poucos parágrafos curtos) — não uma lista de requisitos técnicos.

SAÍDA:
- Retorne APENAS o texto refinado da descrição da funcionalidade.
- Não adicione introduções como "Aqui está sua descrição..." ou explicações.
- Não use Markdown (negrito, listas, tabelas) — retorne texto puro em parágrafos.
INSTRUCTIONS;
    }
}
