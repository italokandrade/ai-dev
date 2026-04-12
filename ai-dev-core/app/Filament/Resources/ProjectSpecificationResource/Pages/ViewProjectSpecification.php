<?php

namespace App\Filament\Resources\ProjectSpecificationResource\Pages;

use App\Filament\Resources\ProjectSpecificationResource;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\ProjectSpecification;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectSpecification extends ViewRecord
{
    protected static string $resource = ProjectSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Aprovar especificação → cria módulos/submódulos automaticamente
            Actions\Action::make('approve')
                ->label('Aprovar e Criar Módulos')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar Especificação Técnica')
                ->modalDescription('Ao aprovar, os módulos e submódulos listados serão criados automaticamente no projeto. Esta ação não pode ser desfeita.')
                ->modalSubmitActionLabel('Sim, aprovar e criar módulos')
                ->action(function () {
                    /** @var ProjectSpecification $spec */
                    $spec = $this->record;

                    if ($spec->isApproved()) {
                        Notification::make()
                            ->title('Esta especificação já foi aprovada.')
                            ->warning()
                            ->send();
                        return;
                    }

                    if (empty($spec->ai_specification)) {
                        Notification::make()
                            ->title('Especificação ainda não foi gerada pela IA.')
                            ->body('Aguarde a conclusão do processamento e recarregue a página.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $spec->approve(auth()->user());

                    Notification::make()
                        ->title('Especificação aprovada!')
                        ->body('Os módulos e submódulos foram criados no projeto com sucesso.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['approved_at']);
                })
                ->visible(fn () => ! $this->record->isApproved() && $this->record->ai_specification !== null),

            // Regenerar especificação com a mesma descrição
            Actions\Action::make('regenerate')
                ->label('Regenerar com IA')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerar Especificação')
                ->modalDescription('A IA irá reprocessar a descrição original e substituir a especificação atual. Use quando o resultado não estiver satisfatório.')
                ->action(function () {
                    /** @var ProjectSpecification $spec */
                    $spec = $this->record;

                    if ($spec->isApproved()) {
                        Notification::make()
                            ->title('Não é possível regenerar uma especificação já aprovada.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Limpa a especificação atual e re-dispara o job
                    $spec->update(['ai_specification' => null]);
                    GenerateProjectSpecificationJob::dispatch($spec);

                    Notification::make()
                        ->title('Regeneração iniciada')
                        ->body('A IA está reprocessando a descrição. Aguarde e recarregue a página.')
                        ->info()
                        ->send();
                })
                ->visible(fn () => ! $this->record->isApproved()),

            Actions\Action::make('view_project')
                ->label('Ver Projeto')
                ->icon('heroicon-o-folder-open')
                ->color('gray')
                ->url(fn () => route('filament.admin.resources.projects.view', $this->record->project_id)),
        ];
    }
}
