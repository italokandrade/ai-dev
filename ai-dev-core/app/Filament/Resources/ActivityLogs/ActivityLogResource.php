<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages;
use Spatie\Activitylog\Models\Activity;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\ViewAction;
use Illuminate\Support\HtmlString;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Logs de Atividades';

    protected static ?string $modelLabel = 'Log de Atividade';

    protected static ?string $pluralModelLabel = 'Logs de Atividades';

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

    /**
     * Mapa de tradução de Models para nomes amigáveis em PT-BR.
     * Utilizado no filtro dinâmico e na coluna "Módulo".
     */
    protected static function modelLabelMap(): array
    {
        return [
            'App\Models\Project'              => 'Projeto',
            'App\Models\ProjectModule'        => 'Módulo',
            'App\Models\ProjectSpecification' => 'Especificação',
            'App\Models\ProjectQuotation'     => 'Orçamento',
            'App\Models\Task'                 => 'Tarefa',
            'App\Models\Subtask'              => 'Subtarefa',
            'App\Models\AgentConfig'          => 'Agente',
            'App\Models\SocialAccount'        => 'Conta Social',
            'App\Models\SystemSetting'        => 'Config. Sistema',
            'App\Models\User'                 => 'Usuário',
        ];
    }

    /**
     * Traduz o FQCN do Model para nome amigável.
     */
    protected static function translateModel(?string $fqcn): string
    {
        if (empty($fqcn)) return '—';
        return static::modelLabelMap()[$fqcn]
            ?? class_basename($fqcn); // fallback: nome curto do Model
    }

    /**
     * Carrega DINAMICAMENTE os subject_types existentes na tabela activity_log,
     * montando o dropdown de filtro a partir dos dados reais — sem hardcode.
     */
    protected static function dynamicSubjectTypes(): array
    {
        try {
            return Activity::query()
                ->distinct('subject_type')
                ->whereNotNull('subject_type')
                ->pluck('subject_type')
                ->mapWithKeys(fn (string $fqcn) => [$fqcn => static::translateModel($fqcn)])
                ->sort()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Usuário')
                    ->placeholder('Sistema'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Módulo')
                    ->formatStateUsing(fn ($state) => static::translateModel($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Evento')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                // Filtro de evento (estático — as opções não mudam)
                Tables\Filters\SelectFilter::make('event')
                    ->label('Evento')
                    ->options([
                        'created' => 'Criação',
                        'updated' => 'Atualização',
                        'deleted' => 'Exclusão',
                    ]),
                // Filtro de módulo — DINÂMICO: carrega os subject_types
                // presentes na tabela activity_log em tempo real
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Módulo')
                    ->options(fn () => static::dynamicSubjectTypes()),
                // Filtro de usuário causador — carregado via query manual
                // (causer é morphTo, ->relationship() não funciona com morphTo)
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Usuário')
                    ->options(function () {
                        try {
                            return \App\Models\User::query()
                                ->whereIn('id', Activity::query()->distinct('causer_id')->whereNotNull('causer_id')->pluck('causer_id'))
                                ->pluck('name', 'id')
                                ->all();
                        } catch (\Throwable) {
                            return [];
                        }
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Detalhes do Log')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            \Filament\Infolists\Components\TextEntry::make('created_at')->label('Data/Hora')->dateTime(),
                            \Filament\Infolists\Components\TextEntry::make('causer.name')->label('Usuário')->placeholder('Sistema'),
                            \Filament\Infolists\Components\TextEntry::make('event')->label('Evento')->badge(),
                        ]),
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Infolists\Components\TextEntry::make('subject_type')
                                ->label('Módulo')
                                ->getStateUsing(fn ($record) => static::translateModel($record->subject_type)),
                            \Filament\Infolists\Components\TextEntry::make('description')->label('Descrição'),
                        ]),
                    ]),
                \Filament\Schemas\Components\Section::make('Alterações')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Infolists\Components\TextEntry::make('properties.old')
                                ->label('Dados Anteriores')
                                ->getStateUsing(fn ($record) => static::formatData($record->properties['old'] ?? null))
                                ->html(),
                            \Filament\Infolists\Components\TextEntry::make('properties.attributes')
                                ->label('Novos Dados')
                                ->getStateUsing(fn ($record) => static::formatData($record->properties['attributes'] ?? null))
                                ->html(),
                        ]),
                    ]),
            ]);
    }

    protected static function formatData(?array $data): ?string
    {
        if (empty($data)) return null;
        $html = '<div class="space-y-1 font-mono text-xs">';
        foreach ($data as $key => $value) {
            $val = is_array($value) ? json_encode($value) : (string)$value;
            if (strlen($val) > 50) $val = substr($val, 0, 50) . '...';
            $html .= "<div><span class='font-bold text-primary-600'>{$key}:</span> <span>{$val}</span></div>";
        }
        return $html . '</div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
