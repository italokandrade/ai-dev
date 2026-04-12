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

            // Gerar nova especificação via IA
            Actions\Action::make('generate_spec')
                ->label('Gerar Especificação com IA')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading('Gerar Especificação Técnica')
                ->modalDescription('A IA irá analisar a descrição do projeto e propor uma arquitetura completa com módulos e submódulos.')
                ->modalSubmitActionLabel('Enviar para IA')
                ->form([
                    Forms\Components\Textarea::make('user_description')
                        ->label('Descrição do Sistema')
                        ->helperText('Pode copiar da descrição já cadastrada ou reescrever livremente.')
                        ->default(fn () => $this->record->currentSpecification?->user_description ?? '')
                        ->rows(6)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $lastVersion = ProjectSpecification::where('project_id', $this->record->id)
                        ->max('version') ?? 0;

                    $spec = ProjectSpecification::create([
                        'project_id' => $this->record->id,
                        'user_description' => $data['user_description'],
                        'version' => $lastVersion + 1,
                    ]);

                    GenerateProjectSpecificationJob::dispatch($spec);

                    Notification::make()
                        ->title('Especificação enviada para geração')
                        ->body('A IA está processando. Aguarde e recarregue a página para ver o resultado.')
                        ->info()
                        ->send();
                }),

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
