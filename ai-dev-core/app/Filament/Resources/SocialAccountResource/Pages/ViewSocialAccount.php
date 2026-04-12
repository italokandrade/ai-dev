<?php

namespace App\Filament\Resources\SocialAccountResource\Pages;

use App\Filament\Resources\SocialAccountResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSocialAccount extends ViewRecord
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            Actions\Action::make('toggle_active')
                ->label(fn (): string => $this->record->is_active ? 'Desativar' : 'Ativar')
                ->icon(fn (): string => $this->record->is_active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color(fn (): string => $this->record->is_active ? 'warning' : 'success')
                ->action(function (): void {
                    $this->record->update(['is_active' => ! $this->record->is_active]);

                    Notification::make()
                        ->title($this->record->is_active ? 'Conta ativada' : 'Conta desativada')
                        ->success()
                        ->send();

                    $this->refreshFormData(['is_active']);
                }),
        ];
    }
}
