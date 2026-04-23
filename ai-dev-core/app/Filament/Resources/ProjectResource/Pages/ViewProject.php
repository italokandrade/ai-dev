<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Components\NavigationTree;
use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectPrdJob;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\ProjectSpecification;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return NavigationTree::forProject($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('generateProjectPrd')
                ->label('Gerar PRD do Projeto')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Gerar PRD do Projeto')
                ->modalDescription('A IA irá gerar o PRD global do projeto, definindo os módulos de alto nível. Isso pode levar alguns minutos.')
                ->modalSubmitActionLabel('Gerar PRD')
                ->action(function () {
                    GenerateProjectPrdJob::dispatch($this->record);

                    Notification::make()
                        ->title('Geração do PRD iniciada')
                        ->body('O PRD do projeto está sendo gerado. Acesse a aba "PRD do Projeto" em alguns instantes.')
                        ->success()
                        ->send();
                })
                ->visible(fn () => empty($this->record->prd_payload) || !empty($this->record->prd_payload['_status'] ?? '')),

            Actions\Action::make('viewProjectPrd')
                ->label('Ver PRD Completo')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->modalHeading(fn () => "PRD Master — {$this->record->name}")
                ->modalContent(fn () => view('filament.project-prd-viewer', ['prd' => $this->record->prd_payload]))
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                ),

            Actions\Action::make('approveProjectPrd')
                ->label('✅ Aprovar PRD — Criar Módulos')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD e Criar Módulos')
                ->modalDescription('Ao aprovar, os módulos de alto nível serão criados a partir do PRD. Submódulos e tasks serão definidos individualmente dentro de cada módulo.')
                ->modalSubmitActionLabel('Aprovar e Criar Módulos')
                ->action(function () {
                    try {
                        $this->record->approvePrd();
                        $this->record->createModulesFromPrd();

                        Notification::make()
                            ->title('PRD aprovado — Módulos criados!')
                            ->body('Os módulos foram criados. Acesse cada módulo e gere seu PRD individual para definir submódulos ou tasks.')
                            ->success()
                            ->send();

                        $this->redirect(ProjectModuleResource::getUrl('index'));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao aprovar PRD')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && !empty($this->record->prd_payload['modules'] ?? [])
                    && !$this->record->isPrdApproved()
                ),

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
