<?php

namespace App\Filament\Resources\SystemSettings\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SystemSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('value')
                    ->columnSpanFull(),
            ]);
    }
}
