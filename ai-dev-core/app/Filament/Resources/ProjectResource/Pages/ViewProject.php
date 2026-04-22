<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\ProjectSpecification;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),


            // Aprovar especificação atual → cria módulos/submódulos
            Actions\Action::make('approve_spec')
                ->label('Aprovar Especificação')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar Especificação Técnica')
                ->modalDescription('Ao aprovar, os módulos e submódulos propostos pela IA serão criados automaticamente no projeto.')
                ->modalSubmitActionLabel('Sim, aprovar')
                ->action(function () {
                    $spec = $this->record->currentSpecification;

                    if ($spec && ! $spec->isApproved()) {
                        $spec->approve(auth()->user());

                        Notification::make()
                            ->title('Especificação aprovada!')
                            ->body('Módulos e submódulos criados. As tasks e o orçamento serão gerados automaticamente pela IA em instantes.')
                            ->success()
                            ->send();

                        $this->refreshFormData([]);
                    }
                })
                ->visible(fn () => $this->record->currentSpecification && ! $this->record->currentSpecification->isApproved()),

            // Ver orçamento gerado
            Actions\Action::make('view_quotation')
                ->label('Ver Orçamento')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->url(fn () => route('filament.admin.resources.project-quotations.view', $this->record->activeQuotation))
                ->openUrlInNewTab(false)
                ->visible(fn () => $this->record->activeQuotation !== null),
        ];
    }
}
