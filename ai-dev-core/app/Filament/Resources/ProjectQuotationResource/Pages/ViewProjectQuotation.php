<?php

namespace App\Filament\Resources\ProjectQuotationResource\Pages;

use App\Ai\Agents\QuotationAgent;
use App\Filament\Resources\ProjectQuotationResource;
use App\Models\ProjectQuotation;
use App\Services\AiRuntimeConfigService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectQuotation extends ViewRecord
{
    protected static string $resource = ProjectQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('estimate_with_ai')
                ->label('Estimar com IA')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Estimar horas com IA')
                ->modalDescription('A IA vai analisar a descrição do projeto e sugerir as horas por área. Os valores atuais serão substituídos.')
                ->visible(fn () => filled($this->getRecord()->project_description))
                ->action(function () {
                    /** @var ProjectQuotation $record */
                    $record = $this->getRecord();

                    try {
                        $prompt = $this->buildEstimationPrompt($record);
                        $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);
                        $response = QuotationAgent::make()->prompt(
                            $prompt,
                            provider: $aiConfig['provider'],
                            model: $aiConfig['model'],
                        );
                        $raw = trim((string) $response);
                        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
                        $raw = preg_replace('/\s*```$/', '', $raw);
                        $hours = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                        $record->fill([
                            'backend_hours' => $hours['backend_hours'] ?? $record->backend_hours,
                            'frontend_hours' => $hours['frontend_hours'] ?? $record->frontend_hours,
                            'mobile_hours' => $hours['mobile_hours'] ?? $record->mobile_hours,
                            'database_hours' => $hours['database_hours'] ?? $record->database_hours,
                            'devops_hours' => $hours['devops_hours'] ?? $record->devops_hours,
                            'design_hours' => $hours['design_hours'] ?? $record->design_hours,
                            'testing_hours' => $hours['testing_hours'] ?? $record->testing_hours,
                            'security_hours' => $hours['security_hours'] ?? $record->security_hours,
                            'pm_hours' => $hours['pm_hours'] ?? $record->pm_hours,
                        ]);

                        $record->recalculate();
                        $record->save();

                        Notification::make()
                            ->title('Estimativa gerada pela IA')
                            ->body(
                                "Total: {$record->total_human_hours}h | ".
                                'Custo humano: R$ '.number_format($record->total_human_cost, 2, ',', '.').' | '.
                                'AI-Dev: R$ '.number_format($record->ai_dev_price, 2, ',', '.')
                            )
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Falha ao gerar estimativa')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('recalculate')
                ->label('Recalcular')
                ->icon('heroicon-o-calculator')
                ->color('info')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->recalculate();
                    $record->save();

                    Notification::make()
                        ->title('Orçamento recalculado com sucesso')
                        ->body(
                            'Custo humano: R$ '.number_format($record->total_human_cost, 2, ',', '.').
                            ' | AI-Dev: R$ '.number_format($record->ai_dev_price, 2, ',', '.').
                            ' | Economia: '.number_format($record->savings_percentage, 1, ',', '.').'%'
                        )
                        ->success()
                        ->send();
                }),

            Actions\Action::make('mark_sent')
                ->label('Marcar como Enviado')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn () => $this->getRecord()->status === 'draft')
                ->action(function () {
                    $this->getRecord()->update(['status' => 'sent', 'sent_at' => now()]);
                    Notification::make()->title('Orçamento marcado como enviado')->success()->send();
                }),

            Actions\Action::make('mark_approved')
                ->label('Aprovar Orçamento e Instalar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => in_array($this->getRecord()->status, ['draft', 'sent']))
                ->action(function () {
                    $this->getRecord()->approveAndStartScaffold();

                    Notification::make()
                        ->title('Orçamento aprovado')
                        ->body('A instalação TALL completa será iniciada em background se o Projeto Alvo ainda não tiver scaffold.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function buildEstimationPrompt(ProjectQuotation $record): string
    {
        $complexity = ProjectQuotation::COMPLEXITY_LABELS[$record->complexity_level] ?? 'Médio';
        $urgency = ProjectQuotation::URGENCY_LABELS[$record->urgency_level] ?? 'Normal';

        return <<<PROMPT
Estime as horas de trabalho por área para o seguinte projeto de software:

PROJETO: {$record->project_name}
COMPLEXIDADE: {$complexity}
URGÊNCIA: {$urgency}
DESCRIÇÃO: {$record->project_description}

Retorne APENAS este JSON com os valores inteiros de horas estimadas (use 0 se a área não se aplica):
{
  "backend_hours": 0,
  "frontend_hours": 0,
  "mobile_hours": 0,
  "database_hours": 0,
  "devops_hours": 0,
  "design_hours": 0,
  "testing_hours": 0,
  "security_hours": 0,
  "pm_hours": 0,
  "justification": "Breve justificativa das estimativas"
}
PROMPT;
    }
}
