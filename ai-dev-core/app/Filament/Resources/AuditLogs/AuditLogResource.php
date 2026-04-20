<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Infolists;
use Filament\Actions\ViewAction;
use Illuminate\Support\HtmlString;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Logs de Auditoria';

    protected static ?string $modelLabel = 'Log de Auditoria';

    protected static ?string $pluralModelLabel = 'Logs de Auditoria';

    protected static ?int $navigationSort = 100;

    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Informações Gerais')
                    ->schema([
                        Grid::make(3)->schema([
                            Infolists\Components\TextEntry::make('created_at')
                                ->label('Data/Hora')
                                ->dateTime(),
                            Infolists\Components\TextEntry::make('user.name')
                                ->label('Usuário')
                                ->placeholder('Sistema'),
                            Infolists\Components\TextEntry::make('action')
                                ->label('Ação')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'created' => 'success',
                                    'updated' => 'warning',
                                    'deleted' => 'danger',
                                    default => 'gray',
                                }),
                        ]),
                        Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('auditable_type')
                                ->label('Módulo')
                                ->formatStateUsing(fn ($state) => str_replace('App\\Models\\', '', $state)),
                            Infolists\Components\TextEntry::make('auditable_id')
                                ->label('ID do Registro'),
                        ]),
                    ]),

                Section::make('Alterações de Dados')
                    ->schema([
                        Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('old_values')
                                ->label('Dados Anteriores')
                                ->getStateUsing(fn (AuditLog $record) => static::formatAuditData($record->old_values))
                                ->placeholder('Nenhum dado anterior'),
                            Infolists\Components\TextEntry::make('new_values')
                                ->label('Novos Dados')
                                ->getStateUsing(fn (AuditLog $record) => static::formatAuditData($record->new_values))
                                ->placeholder('Nenhum dado novo'),
                        ]),
                    ]),

                Section::make('Contexto da Rede')
                    ->schema([
                        Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('ip_address')
                                ->label('Endereço IP'),
                            Infolists\Components\TextEntry::make('user_agent')
                                ->label('Navegador/User Agent'),
                        ]),
                    ])->collapsible()->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->placeholder('Sistema')
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Ação')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Módulo')
                    ->formatStateUsing(fn ($state) => str_replace('App\\Models\\', '', $state)),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('ID do Registro'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'created' => 'Criação',
                        'updated' => 'Atualização',
                        'deleted' => 'Exclusão',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }

    protected static function formatAuditData(mixed $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data)) {
            return null;
        }

        $formatted = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'id'])) continue;

            $displayValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
            
            if (mb_strlen($displayValue) > 50) {
                $displayValue = mb_substr($displayValue, 0, 50) . '...';
            }

            $formatted[] = "{$key}: {$displayValue}";
        }

        return implode("\n", $formatted);
    }
}
