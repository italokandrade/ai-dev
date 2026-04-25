<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Jobs\SyncProjectRepositoryJob;
use App\Models\ProjectSpecification;
use App\Services\StandardProjectModuleService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['local_path'] = '/var/www/html/projetos/'.$data['name'];

        return $data;
    }

    protected function afterCreate(): void
    {
        $project = $this->record;

        app(StandardProjectModuleService::class)->syncProject($project);

        // 1. Criar especificação com a descrição do usuário
        $specification = ProjectSpecification::create([
            'project_id' => $project->id,
            'user_description' => $project->description ?? '',
            'version' => 1,
        ]);

        // 2. Disparar geração da especificação técnica pela IA
        GenerateProjectSpecificationJob::dispatch($specification);

        // 3. Sincronizar somente documentação inicial no repositório do alvo.
        SyncProjectRepositoryJob::dispatch($project->fresh());

        Notification::make()
            ->title('Projeto criado com sucesso!')
            ->body("A documentação inicial do projeto '{$project->name}' será sincronizada. A instalação TALL completa só começa após aprovação do orçamento.")
            ->success()
            ->persistent()
            ->send();
    }
}
