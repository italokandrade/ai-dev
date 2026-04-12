<?php

namespace App\Filament\Resources\ProjectSpecificationResource\Pages;

use App\Filament\Resources\ProjectSpecificationResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\ProjectSpecification;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectSpecification extends CreateRecord
{
    protected static string $resource = ProjectSpecificationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Determina a próxima versão para este projeto
        $lastVersion = ProjectSpecification::where('project_id', $data['project_id'])
            ->max('version') ?? 0;

        $data['version'] = $lastVersion + 1;

        return $data;
    }

    protected function afterCreate(): void
    {
        // Dispara o job para gerar a especificação via IA
        GenerateProjectSpecificationJob::dispatch($this->record);

        Notification::make()
            ->title('Especificação enviada para geração')
            ->body('A IA está processando sua descrição. Aguarde alguns instantes e recarregue a página.')
            ->info()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
