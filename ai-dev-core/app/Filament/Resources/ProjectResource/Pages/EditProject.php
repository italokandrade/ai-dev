<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Models\ProjectSpecification;
use App\Jobs\GenerateProjectSpecificationJob;
use Filament\Notifications\Notification;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    private string $newUserDescription = '';
    private string $originalUserDescription = '';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $project = $this->record;
        $spec = $project->currentSpecification;
        
        if ($spec) {
            $data['user_description'] = $spec->user_description;
            $this->originalUserDescription = $spec->user_description;
        }
        
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->newUserDescription = $data['user_description'] ?? '';
        unset($data['user_description']);
        
        return $data;
    }

    protected function afterSave(): void
    {
        $project = $this->record;
        $currentSpec = $project->currentSpecification;

        // Apenas gerar uma nova especificação se o texto tiver mudado e não estiver vazio
        if ($this->newUserDescription !== '' && (!$currentSpec || $currentSpec->user_description !== $this->newUserDescription)) {
            $newVersion = $currentSpec ? $currentSpec->version + 1 : 1;
            
            $specification = ProjectSpecification::create([
                'project_id' => $project->id,
                'user_description' => $this->newUserDescription,
                'version' => $newVersion,
            ]);

            GenerateProjectSpecificationJob::dispatch($specification);
            
            Notification::make()
                ->title('Nova especificação disparada!')
                ->body('A IA está analisando sua nova descrição para gerar módulos atualizados em background.')
                ->success()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
