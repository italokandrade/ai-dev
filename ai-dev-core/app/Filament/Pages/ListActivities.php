<?php

namespace App\Filament\Pages;

use pxlrbt\FilamentActivityLog\Pages\ListActivities as BaseListActivities;

class ListActivities extends BaseListActivities
{
    protected static ?string $navigationLabel = 'Logs de Atividades';

    protected static ?string $title = 'Logs de Atividades';

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    protected static ?int $navigationSort = 100;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';
}
