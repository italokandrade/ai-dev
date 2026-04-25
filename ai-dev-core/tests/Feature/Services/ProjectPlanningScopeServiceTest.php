<?php

use App\Models\Project;
use App\Models\ProjectFeature;
use App\Services\ProjectPlanningScopeService;

test('planning scope keeps landing page prd small and rejects unrequested platform modules', function () {
    $project = Project::create([
        'name' => 'landing-test',
        'description' => 'Landing page simples para apresentar um profissional e capturar contatos.',
        'status' => 'active',
    ]);

    ProjectFeature::create([
        'project_id' => $project->id,
        'type' => 'frontend',
        'title' => 'Hero e chamada para ação',
        'description' => 'Apresenta a proposta principal da página.',
    ]);

    $service = app(ProjectPlanningScopeService::class);
    $prd = $service->sanitizeProjectPrd($project->fresh(['features']), [
        'title' => 'Landing - PRD',
        'objective' => 'Criar uma landing page.',
        'modules' => [
            ['name' => 'CRM e Leads', 'description' => 'Gestao completa de oportunidades', 'priority' => 'high'],
            ['name' => 'Orquestração de Agentes', 'description' => 'Executar agentes autonomos', 'priority' => 'high'],
            ['name' => 'Landing Page', 'description' => 'Pagina publica', 'priority' => 'high'],
        ],
    ]);

    expect($prd['planning_profile']['key'])->toBe('simple_landing')
        ->and(collect($prd['modules'])->pluck('name')->all())->toBe([
            'Landing Page',
            'Captacao de Contatos',
        ]);
});

test('planning scope trims generated features for simple landing pages', function () {
    $project = Project::create([
        'name' => 'landing-feature-test',
        'description' => 'Landing page de servicos com formulario de contato.',
        'status' => 'active',
    ]);

    $features = app(ProjectPlanningScopeService::class)->sanitizeFeatures($project, 'backend', [
        ['title' => 'Motor de Cotação de Projetos', 'description' => 'Gera propostas comerciais completas.'],
        ['title' => 'Webhook para Atualizações Externas', 'description' => 'Recebe eventos externos.'],
        ['title' => 'Recebimento de Contato', 'description' => 'Registra mensagens enviadas pelo formulario.'],
    ]);

    expect(collect($features)->pluck('title')->all())->toBe(['Recebimento de Contato']);
});

test('planning scope preserves rich module metadata from project prd', function () {
    $project = Project::create([
        'name' => 'portfolio-site',
        'description' => 'Site institucional com portfolio, artigos e formulario de contato.',
        'status' => 'active',
    ]);

    $prd = app(ProjectPlanningScopeService::class)->sanitizeProjectPrd($project->fresh(['features']), [
        'title' => 'Portfolio - PRD',
        'objective' => 'Criar site publico.',
        'modules' => [
            [
                'name' => 'Portfolio Publico',
                'description' => 'Apresenta projetos publicados.',
                'priority' => 'high',
                'dependencies' => ['Conteudo Publico'],
                'source_features' => ['Listagem de projetos'],
                'business_outcomes' => ['Visitante entende a experiencia do profissional'],
                'primary_user_journeys' => ['Visitante explora projetos e solicita contato'],
                'content_or_data_requirements' => ['Projetos publicados com imagens e tecnologias'],
                'acceptance_signals' => ['Projetos aparecem com URL publica'],
                'scope_boundaries' => ['Nao assume CRM interno'],
            ],
        ],
    ]);

    expect($prd['modules'][0]['source_features'])->toBe(['Listagem de projetos'])
        ->and($prd['modules'][0]['business_outcomes'])->toBe(['Visitante entende a experiencia do profissional'])
        ->and($prd['modules'][0]['scope_boundaries'])->toBe(['Nao assume CRM interno']);
});

test('planning scope preserves rich generated landing modules before applying fallback modules', function () {
    $project = Project::create([
        'name' => 'landing-rich-test',
        'description' => 'Landing page simples com formulario de contato.',
        'status' => 'active',
    ]);

    $prd = app(ProjectPlanningScopeService::class)->sanitizeProjectPrd($project->fresh(['features']), [
        'title' => 'Landing Rica - PRD',
        'objective' => 'Criar landing page.',
        'modules' => [
            [
                'name' => 'Experiencia Publica e Conversao',
                'description' => 'Hero, prova social, proposta de valor e CTAs.',
                'priority' => 'high',
                'source_features' => ['Hero publico'],
                'business_outcomes' => ['Aumentar solicitacoes qualificadas'],
                'primary_user_journeys' => ['Visitante entende a oferta e envia contato'],
            ],
        ],
    ]);

    $richModule = collect($prd['modules'])->firstWhere('name', 'Experiencia Publica e Conversao');

    expect($richModule['business_outcomes'])->toBe(['Aumentar solicitacoes qualificadas'])
        ->and(collect($prd['modules'])->pluck('name')->all())->toContain('Captacao de Contatos');
});
