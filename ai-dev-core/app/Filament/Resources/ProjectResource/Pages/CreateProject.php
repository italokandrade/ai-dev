<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Jobs\ScaffoldProjectJob;
use App\Models\ProjectSpecification;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Guardar campos temporários e removê-los do data do Project
        $this->userDescription = $data['user_description'] ?? '';
        $this->dbPassword = $data['db_password'] ?? '';

        unset($data['user_description'], $data['db_password']);

        $data['local_path'] = '/var/www/html/projetos/' . $data['name'];

        return $data;
    }

    protected function afterCreate(): void
    {
        $project = $this->record;

        // 1. Criar especificação com a descrição do usuário
        $specification = ProjectSpecification::create([
            'project_id' => $project->id,
            'user_description' => $this->userDescription,
            'version' => 1,
        ]);

        // 2. Disparar geração da especificação técnica pela IA
        GenerateProjectSpecificationJob::dispatch($specification);

        // 3. Disparar scaffold do projeto (instalar_projeto.sh)
        ScaffoldProjectJob::dispatch($project, $this->dbPassword);

        Notification::make()
            ->title('Projeto criado com sucesso!')
            ->body("O scaffold do projeto '{$project->name}' foi iniciado em background. A IA está gerando a especificação técnica e os módulos.")
            ->success()
            ->persistent()
            ->send();
    }

    private string $userDescription = '';
    private string $dbPassword = '';
}
