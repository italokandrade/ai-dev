<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Models\User;
use App\Services\SystemSurfaceMapService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Logs de Atividades';

    protected static ?string $modelLabel = 'Log de Atividade';

    protected static ?string $pluralModelLabel = 'Logs de Atividades';

    protected static ?int $navigationSort = 100;

    protected static string|\UnitEnum|null $navigationGroup = 'Segurança';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || $user?->can('ViewAny:Activity'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isAdmin() || $user?->can('View:Activity'));
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
        return SystemSurfaceMapService::activitySubjectLabels();
    }

    /**
     * Traduz o FQCN do Model para nome amigável.
     */
    protected static function translateModel(?string $fqcn): string
    {
        if (empty($fqcn)) {
            return '—';
        }

        return SystemSurfaceMapService::modelLabel($fqcn);
    }

    /**
     * Carrega DINAMICAMENTE os subject_types existentes na tabela activity_log,
     * montando o dropdown de filtro a partir dos dados reais — sem hardcode.
     */
    protected static function dynamicSubjectTypes(): array
    {
        try {
            $loggedTypes = Activity::query()
                ->distinct('subject_type')
                ->whereNotNull('subject_type')
                ->pluck('subject_type')
                ->all();

            return collect(SystemSurfaceMapService::activitySubjectFilterOptions($loggedTypes))
                ->sort()
                ->all();
        } catch (\Throwable) {
            return SystemSurfaceMapService::activitySubjectFilterOptions();
        }
    }

    protected static function eventLabel(?string $event): string
    {
        if (! $event) {
            return '—';
        }

        return [
            'created' => 'Criação',
            'updated' => 'Atualização',
            'deleted' => 'Exclusão',
            'role_attached' => 'Perfil atribuído',
            'role_detached' => 'Perfil removido',
            'permission_attached' => 'Permissão atribuída',
            'permission_detached' => 'Permissão removida',
            'dashboard_chat_message' => 'Chat utilizado',
            'dashboard_chat_clear' => 'Chat limpo',
        ][$event] ?? str($event)->replace('_', ' ')->headline()->toString();
    }

    protected static function dynamicEvents(): array
    {
        try {
            $events = Activity::query()
                ->distinct('event')
                ->whereNotNull('event')
                ->pluck('event')
                ->all();

            return collect($events)
                ->mapWithKeys(fn (string $event) => [$event => static::eventLabel($event)])
                ->sort()
                ->all();
        } catch (\Throwable) {
            return [
                'created' => 'Criação',
                'updated' => 'Atualização',
                'deleted' => 'Exclusão',
            ];
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
                    ->formatStateUsing(fn (?string $state): string => static::eventLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'role_attached', 'permission_attached' => 'success',
                        'role_detached', 'permission_detached' => 'danger',
                        'dashboard_chat_message', 'dashboard_chat_clear' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                // Filtro de evento — dinâmico para cobrir eventos de ACL, chat e futuros fluxos
                Tables\Filters\SelectFilter::make('event')
                    ->label('Evento')
                    ->options(fn () => static::dynamicEvents()),
                // Filtro de módulo — DINÂMICO: carrega os subject_types
                // presentes na tabela activity_log em tempo real
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Módulo')
                    ->options(fn () => static::dynamicSubjectTypes())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereIn('subject_type', SystemSurfaceMapService::subjectTypesForFilter($value));
                    }),
                // Filtro de usuário causador — carregado via query manual
                // (causer é morphTo, ->relationship() não funciona com morphTo)
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Usuário')
                    ->options(function () {
                        try {
                            return User::query()
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
                Section::make('Detalhes do Log')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('created_at')->label('Data/Hora')->dateTime(),
                            TextEntry::make('causer.name')->label('Usuário')->placeholder('Sistema'),
                            TextEntry::make('event')->label('Evento')->badge(),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('subject_type')
                                ->label('Módulo')
                                ->getStateUsing(fn ($record) => static::translateModel($record->subject_type)),
                            TextEntry::make('description')->label('Descrição'),
                        ]),
                    ]),
                Section::make('Alterações')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('properties.old')
                                ->label('Dados Anteriores')
                                ->getStateUsing(fn ($record) => static::formatData($record->properties['old'] ?? null))
                                ->html(),
                            TextEntry::make('properties.attributes')
                                ->label('Novos Dados')
                                ->getStateUsing(fn ($record) => static::formatData($record->properties['attributes'] ?? null))
                                ->html(),
                        ]),
                    ]),
            ]);
    }

    protected static function formatData(?array $data): ?string
    {
        if (empty($data)) {
            return null;
        }
        $html = '<div class="space-y-1 font-mono text-xs">';
        foreach ($data as $key => $value) {
            $val = is_array($value) ? json_encode($value) : (string) $value;
            if (strlen($val) > 50) {
                $val = substr($val, 0, 50).'...';
            }
            $html .= "<div><span class='font-bold text-primary-600'>".e((string) $key).':</span> <span>'.e($val).'</span></div>';
        }

        return $html.'</div>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
