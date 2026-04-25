<?php

use App\Models\Project;
use App\Services\ProjectArchitectureArtifactService;

test('project architecture artifact service renders mermaid domain model documents', function () {
    $project = Project::create([
        'name' => 'artifact-project',
        'status' => 'active',
        'blueprint_payload' => [
            'domain_model' => [
                'entities' => [
                    [
                        'name' => 'clientes',
                        'description' => 'Clientes atendidos',
                        'columns' => [
                            ['name' => 'id', 'type' => 'uuid'],
                            ['name' => 'nome', 'type' => 'string'],
                        ],
                    ],
                    [
                        'name' => 'processos',
                        'description' => 'Processos do cliente',
                    ],
                ],
                'relationships' => [
                    [
                        'source' => 'clientes',
                        'target' => 'processos',
                        'type' => 'one_to_many',
                        'foreign_key' => 'cliente_id',
                        'description' => 'Cliente possui processos',
                    ],
                ],
            ],
        ],
    ]);

    $documents = app(ProjectArchitectureArtifactService::class)->documents($project);

    expect($documents)->toHaveKeys([
        'architecture/domain-model.mmd',
        'architecture/domain-model.md',
        'architecture/domain-model.json',
        'architecture/checkpoint-protocol.md',
    ])
        ->and($documents['architecture/domain-model.mmd'])->toContain('CLIENTES ||--o{ PROCESSOS')
        ->and($documents['architecture/domain-model.mmd'])->toContain('uuid id PK')
        ->and($documents['architecture/checkpoint-protocol.md'])->toContain('SQLite');
});
