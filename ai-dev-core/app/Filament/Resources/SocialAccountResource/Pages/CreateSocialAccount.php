<?php

namespace App\Filament\Resources\SocialAccountResource\Pages;

use App\Filament\Resources\SocialAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialAccount extends CreateRecord
{
    protected static string $resource = SocialAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filtra nulls das credenciais antes de salvar
        if (isset($data['credentials']) && is_array($data['credentials'])) {
            $data['credentials'] = array_filter($data['credentials'], fn ($v): bool => filled($v));
        }

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Conta social cadastrada com sucesso';
    }
}
