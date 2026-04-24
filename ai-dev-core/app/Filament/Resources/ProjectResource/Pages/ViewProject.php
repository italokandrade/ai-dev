<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Components\NavigationTree;
use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Jobs\GenerateProjectPrdJob;
use Filament\Actions;
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

            Actions\Action::make('novo_modulo')
                ->label('Novo Módulo')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->url(fn () => ProjectModuleResource::getUrl('create', ['project_id' => $this->record->id])),

            Actions\Action::make('generateProjectPrd')
                ->label('Gerar PRD')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Gerar PRD do Projeto')
                ->modalDescription('A IA irá analisar a descrição e gerar o PRD com os módulos de alto nível. Pode levar alguns minutos.')
                ->modalSubmitActionLabel('Gerar')
                ->action(function () {
                    $this->record->update(['prd_payload' => ['_status' => 'generating']]);
                    GenerateProjectPrdJob::dispatch($this->record->fresh());
                    Notification::make()
                        ->title('PRD sendo gerado...')
                        ->body('O botão será atualizado quando concluído.')
                        ->success()
                        ->send();
                })
                ->visible(fn () =>
                    empty($this->record->prd_payload)
                    || ($this->record->prd_payload['_status'] ?? '') === 'ai_generation_failed'
                ),

            Actions\Action::make('generatingProjectPrd')
                ->label('Gerando PRD...')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->disabled()
                ->visible(fn () => ($this->record->prd_payload['_status'] ?? '') === 'generating'),

            Actions\Action::make('approveProjectPrd')
                ->label('Aprovar PRD — Criar Módulos')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD e Criar Módulos')
                ->modalDescription('Os módulos de alto nível serão criados a partir do PRD. Cada módulo depois recebe seu próprio PRD com submódulos e tasks.')
                ->modalSubmitActionLabel('Aprovar e Criar')
                ->action(function () {
                    try {
                        $this->record->approvePrd();
                        $this->record->createModulesFromPrd();
                        Notification::make()
                            ->title('PRD aprovado — Módulos criados!')
                            ->success()
                            ->send();
                        $this->redirect(ProjectResource::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()->title('Erro')->body($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () =>
                    !empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && !empty($this->record->prd_payload['modules'] ?? [])
                    && !$this->record->isPrdApproved()
                ),
        ];
    }
}
