<?php

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemSettingsPage extends Page
{
    protected string $view = 'filament.pages.system-settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações do Sistema';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuracao';

    protected static ?int $navigationSort = 10;

    public bool $developmentEnabled = false;

    public function mount(): void
    {
        $this->developmentEnabled = SystemSetting::isDevelopmentEnabled();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enable_development')
                ->label('Liberar Desenvolvimento')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Liberar execução dos agentes?')
                ->modalDescription('Ao liberar, os agentes de IA começarão a trabalhar nas tasks pendentes automaticamente. Certifique-se de que o projeto está com as especificações finalizadas.')
                ->modalSubmitActionLabel('Sim, liberar agentes')
                ->visible(fn () => ! SystemSetting::isDevelopmentEnabled())
                ->action(function () {
                    SystemSetting::setDevelopmentEnabled(true);
                    $this->developmentEnabled = true;

                    Notification::make()
                        ->title('Desenvolvimento liberado!')
                        ->body('Os agentes de IA estão autorizados a trabalhar nas tasks pendentes.')
                        ->success()
                        ->send();
                }),

            Action::make('disable_development')
                ->label('Pausar Desenvolvimento')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Pausar execução dos agentes?')
                ->modalDescription('Os agentes em execução atual terminarão o passo atual, mas nenhum novo job de desenvolvimento será iniciado.')
                ->modalSubmitActionLabel('Sim, pausar agentes')
                ->visible(fn () => SystemSetting::isDevelopmentEnabled())
                ->action(function () {
                    SystemSetting::setDevelopmentEnabled(false);
                    $this->developmentEnabled = false;

                    Notification::make()
                        ->title('Desenvolvimento pausado')
                        ->body('Nenhum novo job de agente será iniciado. Jobs em execução completarão seu passo atual.')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
