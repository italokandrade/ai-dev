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
