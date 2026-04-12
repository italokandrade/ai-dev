<?php

namespace App\Filament\Resources\SocialAccountResource\Pages;

use App\Filament\Resources\SocialAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSocialAccount extends EditRecord
{
    protected static string $resource = SocialAccountResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Expande o array de credenciais para campos individuais: credentials.{key}
        // O Filament lida com dot-notation nativamente ao preencher o form
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['credentials']) && is_array($data['credentials'])) {
            $data['credentials'] = array_filter($data['credentials'], fn ($v): bool => filled($v));
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Conta social atualizada';
    }
}
