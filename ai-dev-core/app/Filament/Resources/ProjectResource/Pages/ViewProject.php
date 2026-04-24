<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Components\NavigationTree;
use App\Filament\Components\PrdRenderer;
use App\Filament\Resources\ProjectModuleResource;
use App\Filament\Resources\ProjectResource;
use App\Jobs\CascadeModulePrdJob;
use App\Jobs\GenerateProjectBlueprintJob;
use App\Jobs\GenerateProjectPrdJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getSubheading(): string|Htmlable|null
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
                ->visible(fn () => empty($this->record->prd_payload)
                    || ($this->record->prd_payload['_status'] ?? '') === 'ai_generation_failed'
                ),

            Actions\Action::make('generatingProjectPrd')
                ->label('Gerando PRD...')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->disabled()
                ->visible(fn () => ($this->record->prd_payload['_status'] ?? '') === 'generating'),

            Actions\Action::make('viewProjectPrd')
                ->label('Ver PRD Completo')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->modalHeading(fn () => $this->record->prd_payload['title'] ?? 'PRD do Projeto')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(fn () => PrdRenderer::render($this->record->prd_payload))
                ->visible(fn () => ! empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                ),

            Actions\Action::make('approveProjectPrd')
                ->label('Aprovar PRD — Gerar Blueprint')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar PRD e Gerar Blueprint')
                ->modalDescription('O PRD Master será aprovado e o Blueprint Técnico Global será gerado antes da criação dos módulos.')
                ->modalSubmitActionLabel('Aprovar e Gerar Blueprint')
                ->action(function () {
                    try {
                        $this->record->approvePrd();
                        $this->record->update([
                            'blueprint_payload' => ['_status' => 'generating'],
                            'blueprint_approved_at' => null,
                        ]);
                        GenerateProjectBlueprintJob::dispatch($this->record->fresh());
                        Notification::make()
                            ->title('PRD aprovado — Blueprint em geração')
                            ->body('O Blueprint Técnico será usado como trilho para módulos, submódulos e tasks.')
                            ->success()
                            ->send();
                        $this->redirect(ProjectResource::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()->title('Erro')->body($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => ! empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && ! empty($this->record->prd_payload['modules'] ?? [])
                    && ! $this->record->isPrdApproved()
                ),

            Actions\Action::make('generatingProjectBlueprint')
                ->label('Gerando Blueprint...')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->disabled()
                ->visible(fn () => ($this->record->blueprint_payload['_status'] ?? '') === 'generating'),

            Actions\Action::make('generateProjectBlueprint')
                ->label('Gerar Blueprint')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Gerar Blueprint Técnico')
                ->modalDescription('A IA irá gerar MER/ERD conceitual, casos de uso, workflows, arquitetura e contratos de API em alto nível.')
                ->modalSubmitActionLabel('Gerar')
                ->action(function () {
                    $this->record->update([
                        'blueprint_payload' => ['_status' => 'generating'],
                        'blueprint_approved_at' => null,
                    ]);
                    GenerateProjectBlueprintJob::dispatch($this->record->fresh());
                    Notification::make()
                        ->title('Blueprint sendo gerado...')
                        ->success()
                        ->send();
                })
                ->visible(fn () => $this->record->isPrdApproved()
                    && (
                        empty($this->record->blueprint_payload)
                        || ($this->record->blueprint_payload['_status'] ?? '') === 'ai_generation_failed'
                    )
                ),

            Actions\Action::make('viewProjectBlueprint')
                ->label('Ver Blueprint')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->modalHeading(fn () => $this->record->blueprint_payload['title'] ?? 'Blueprint Técnico')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(function () {
                    $blueprint = $this->record->blueprint_payload;

                    return new HtmlString(
                        '<pre class="text-xs overflow-auto max-h-[70vh] bg-gray-100 dark:bg-gray-800 p-4 rounded whitespace-pre-wrap">'
                        .e(json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                        .'</pre>'
                    );
                })
                ->visible(fn () => ! empty($this->record->blueprint_payload)
                    && ($this->record->blueprint_payload['_status'] ?? '') !== 'generating'
                ),

            Actions\Action::make('approveProjectBlueprint')
                ->label('Aprovar Blueprint — Criar Módulos')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprovar Blueprint e Criar Módulos')
                ->modalDescription('Os módulos raiz serão criados a partir do PRD aprovado. A partir daqui, cada PRD de módulo evolui o Blueprint com campos, relacionamentos, workflows e componentes.')
                ->modalSubmitActionLabel('Aprovar e Criar')
                ->action(function () {
                    try {
                        $this->record->approveBlueprint();
                        $this->record->createModulesFromPrd();
                        Notification::make()
                            ->title('Blueprint aprovado — Módulos criados!')
                            ->success()
                            ->send();
                        $this->redirect(ProjectResource::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()->title('Erro')->body($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => $this->record->isPrdApproved()
                    && ! $this->record->isBlueprintApproved()
                    && $this->record->isBlueprintReady()
                ),

            Actions\Action::make('autoApproveProjectPrd')
                ->label('Auto Aprovar Blueprint — Cascata Completa')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Auto Aprovação em Cascata')
                ->modalDescription('O sistema irá criar os módulos e automaticamente gerar/aprovar os PRDs de todos os módulos e submódulos, evoluindo o Blueprint e criando tasks ao final de cada ramo. Nenhuma task será executada automaticamente. Este processo pode levar vários minutos.')
                ->modalSubmitActionLabel('Iniciar Cascata')
                ->action(function () {
                    try {
                        if (! $this->record->isBlueprintApproved()) {
                            $this->record->approveBlueprint();
                        }

                        $this->record->createModulesFromPrd();

                        $modules = $this->record->modules()->whereNull('parent_id')->get();

                        foreach ($modules as $module) {
                            CascadeModulePrdJob::dispatch($module);
                        }

                        Notification::make()
                            ->title('Cascata iniciada!')
                            ->body("PRD aprovado. Gerando PRDs para {$modules->count()} módulo(s) em background.")
                            ->success()
                            ->send();

                        $this->redirect(ProjectResource::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()->title('Erro')->body($e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () => ! empty($this->record->prd_payload)
                    && empty($this->record->prd_payload['_status'] ?? '')
                    && ! empty($this->record->prd_payload['modules'] ?? [])
                    && $this->record->isPrdApproved()
                    && $this->record->isBlueprintReady()
                ),
        ];
    }
}
