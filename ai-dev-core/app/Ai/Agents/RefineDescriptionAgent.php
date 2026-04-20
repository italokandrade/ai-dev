<?php

namespace App\Ai\Agents;

use App\Services\SystemContextService;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
#[Model('anthropic/claude-opus-4.7')]
#[Temperature(0.7)]
#[MaxTokens(2048)]
#[Timeout(60)]
class RefineDescriptionAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $dynamicContext = SystemContextService::getFullContext();

        return <<<INSTRUCTIONS
Você é um consultor técnico sênior especializado no ecossistema atual do servidor.
Sua tarefa é receber uma descrição informal de um sistema e reescrevê-la como uma
proposta de valor técnica e funcional de alta qualidade.

{$dynamicContext}

REGRAS PARA O REFINAMENTO:
1. Melhore a clareza, coesão e o vocabulário técnico.
2. Certifique-se de que a proposta seja viável para a stack detectada no contexto acima.
3. Foque em benefícios e funcionalidades, não apenas em "como fazer".
4. Mantenha o tom profissional, mas direto e inspirador.
5. Se algo na descrição original parecer tecnicamente impossível ou fora dos padrões das versões detectadas, sugira a alternativa correta.
6. O texto final deve ser em Português do Brasil.

SAÍDA:
- Retorne APENAS o texto refinado.
- Não adicione introduções como "Aqui está sua descrição..." ou explicações.
- Não use Markdown (como negrito ou listas) se o texto original for apenas parágrafos,
  a menos que melhore significativamente a legibilidade.
INSTRUCTIONS;
    }
}
