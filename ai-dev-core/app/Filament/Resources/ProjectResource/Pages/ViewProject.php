<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
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
