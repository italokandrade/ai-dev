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
class RefineDescriptionAgent implements Agent, HasTools
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
Você é um redator especializado em descrições de sistemas e produtos digitais.
Sua tarefa é receber uma descrição informal de um sistema e reescrevê-la de forma
clara, profissional e objetiva, mantendo a essência do que o usuário solicitou.

{$dynamicContext}

REGRAS PARA O REFINAMENTO:
1. Melhore a clareza, coesão e o vocabulário, mas preserve a intenção original do usuário.
2. Mantenha a síntese o mais próxima possível do texto original, a menos que o usuário peça uma mudança específica.
3. O texto deve descrever literalmente o sistema — o que ele faz, para quem serve e quais problemas resolve.
4. NÃO inclua especificações técnicas detalhadas no texto final: NUNCA cite nomes de frameworks, versões de bibliotecas, arquitetura de banco, APIs internas, rotas, ou ferramentas.
5. Você DEVE usar as ferramentas disponíveis para verificar se a descrição é compatível com o ambiente e o stack detectado, mas essa verificação é interna — o resultado final NÃO deve mencionar que você fez verificações ou consultou dados técnicos.
6. NÃO inclua cronogramas, orçamentos, estimativas de horas ou planos de projeto.
7. Foque em benefícios e funcionalidades do ponto de vista do usuário final, não em "como fazer".
8. Se algo na descrição original parecer impossível ou fora dos padrões das versões detectadas no contexto, adapte suavemente sem mencionar o motivo técnico.
9. O texto final deve ser em Português do Brasil.
10. O resultado deve ser um parágrafo fluido (ou poucos parágrafos curtos) — não uma lista de requisitos técnicos.

SAÍDA:
- Retorne APENAS o texto refinado.
- Não adicione introduções como "Aqui está sua descrição..." ou explicações.
- Não use Markdown (negrito, listas, tabelas) — retorne texto puro em parágrafos.
INSTRUCTIONS;
    }
}
