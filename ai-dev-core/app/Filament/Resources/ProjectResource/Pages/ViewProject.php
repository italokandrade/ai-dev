<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\ProjectSpecification;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('regenerate_spec')
                ->label('Regenerar Especificação')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerar Especificação Técnica')
                ->modalDescription('A IA irá gerar uma nova versão da especificação técnica e dos módulos. Módulos existentes NÃO serão apagados.')
                ->action(function () {
                    $project = $this->record;
                    $current = $project->currentSpecification;

                    if (! $current) {
                        Notification::make()
                            ->title('Nenhuma especificação encontrada')
                            ->body('Crie uma especificação primeiro.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $newSpec = ProjectSpecification::create([
                        'project_id' => $project->id,
                        'user_description' => $current->user_description,
                        'version' => $current->version + 1,
                    ]);

                    GenerateProjectSpecificationJob::dispatch($newSpec);

                    Notification::make()
                        ->title('Regeneração iniciada')
                        ->body("Versão {$newSpec->version} da especificação está sendo gerada pela IA.")
                        ->success()
                        ->send();
                })
                ->visible(fn () => $this->record->currentSpecification !== null),

            Actions\Action::make('approve_spec')
                ->label('Aprovar Especificação')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar Especificação Técnica')
                ->modalDescription('Ao aprovar, os módulos serão confirmados e o projeto estará pronto para receber tasks.')
                ->action(function () {
                    $spec = $this->record->currentSpecification;

                    if ($spec && ! $spec->isApproved()) {
                        $spec->approve(auth()->user());

                        Notification::make()
                            ->title('Especificação aprovada!')
                            ->body('O projeto está pronto para receber tasks de desenvolvimento.')
                            ->success()
                            ->send();
                    }
                })
                ->visible(fn () => $this->record->currentSpecification && ! $this->record->currentSpecification->isApproved()),
        ];
    }
}
