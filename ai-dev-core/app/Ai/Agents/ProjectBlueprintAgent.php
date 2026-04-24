<?php

namespace App\Ai\Agents;

use App\Services\SystemContextService;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
#[Timeout(600)]
class ProjectBlueprintAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        if ($provider === 'kimi') {
            return [
                'max_completion_tokens' => 32768,
                'response_format' => ['type' => 'json_object'],
            ];
        }

        return [];
    }

    public function instructions(): Stringable|string
    {
        $dynamicContext = SystemContextService::getFullContext();

        return <<<INSTRUCTIONS
Você é um arquiteto de software sênior especializado em descoberta de domínio,
modelagem progressiva e sistemas Laravel 13 + TALL Stack + Filament v5 + PostgreSQL.

{$dynamicContext}

Sua função é gerar o BLUEPRINT TÉCNICO GLOBAL de um Projeto Alvo depois que o PRD
Master foi aprovado e antes da criação dos módulos.

O Blueprint não é implementação. Ele é um mapa técnico inicial que orienta todos
os PRDs de módulo posteriores.

ARTEFATOS OBRIGATÓRIOS:
1. Modelo de domínio conceitual, em estilo MER/ERD:
   - Identifique entidades/tabelas candidatas.
   - Identifique relacionamentos e cardinalidades.
   - NÃO defina colunas/campos neste nível, exceto se for impossível entender a entidade sem uma chave conceitual.
2. Casos de uso:
   - Atores, objetivos e módulos envolvidos.
3. Workflows:
   - Fluxos de negócio importantes em formato estruturado, compatível com fluxograma/BPMN futuro.
4. Arquitetura:
   - Containers e componentes de alto nível compatíveis com Laravel, Filament, Livewire, filas, integrações e banco.
   - Use visão C4 simplificada, sem desenhar arquivos.
5. Superfície de API/integrações:
   - Liste contratos previstos em alto nível, sem rotas finais detalhadas.
6. Decisões não funcionais:
   - Segurança, auditoria, LGPD, performance, filas, observabilidade e integrações relevantes.

REGRAS:
1. Use o PRD Master e as funcionalidades backend/frontend como fonte de verdade.
2. Não crie módulos novos; use os módulos já listados no PRD.
3. Não implemente, não gere migrations, não gere código, não assuma diretório físico do Projeto Alvo.
4. Prefira poucos artefatos claros a listas enormes.
5. O Blueprint deve ser útil para que PRDs de módulo herdem entidades, workflows e componentes já descobertos.
6. Responda em Português do Brasil.

SAÍDA:
Retorne APENAS um JSON válido, sem markdown, sem texto fora do JSON:

{
  "title": "Nome do Projeto — Blueprint Técnico",
  "artifact_type": "technical_blueprint",
  "source": "project_blueprint_agent",
  "summary": "Resumo técnico curto do desenho global",
  "domain_model": {
    "entities": [
      {
        "name": "clientes",
        "description": "Pessoas ou organizações atendidas pelo sistema",
        "modules": ["Gestão de Clientes"],
        "columns": [],
        "relationships": []
      }
    ],
    "relationships": [
      {
        "source": "clientes",
        "target": "demandas_juridicas",
        "type": "one_to_many",
        "foreign_key": "",
        "description": "Um cliente pode possuir várias demandas"
      }
    ]
  },
  "use_cases": [
    {
      "name": "Cadastrar cliente",
      "actor": "Usuário interno",
      "goal": "Registrar uma nova pessoa ou organização",
      "modules": ["Gestão de Clientes"]
    }
  ],
  "workflows": [
    {
      "name": "Abertura de demanda",
      "notation": "flowchart",
      "description": "Fluxo macro do início até a triagem",
      "modules": ["Demandas Jurídicas"],
      "steps": ["Receber solicitação", "Validar partes", "Classificar especialidade", "Abrir demanda"]
    }
  ],
  "architecture": {
    "containers": [
      {"name": "Aplicação Laravel TALL", "description": "Admin e domínio do Projeto Alvo"}
    ],
    "components": [
      {"name": "Painel Filament", "description": "Operação administrativa e dashboards"}
    ],
    "integrations": [
      {"name": "Tribunais", "description": "Integrações previstas em alto nível"}
    ]
  },
  "api_surface": [
    {
      "name": "Consulta de demandas",
      "purpose": "Expor dados resumidos para integrações autorizadas",
      "consumers": ["Sistema externo autorizado"],
      "modules": ["Demandas Jurídicas"]
    }
  ],
  "non_functional_decisions": ["Auditar operações sensíveis"],
  "open_questions": []
}
INSTRUCTIONS;
    }
}
