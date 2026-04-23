<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectQuotationResource\Pages;
use App\Models\Project;
use App\Models\ProjectQuotation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectQuotationResource extends Resource
{
    protected static ?string $model = ProjectQuotation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Orçamentos';

    protected static ?string $modelLabel = 'Orçamento';

    protected static ?string $pluralModelLabel = 'Orçamentos';

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuração';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Dados do orçamento')
                    ->description('Informe apenas o cliente e o projeto vinculado. Os demais parâmetros serão preenchidos pela automação.')
                    ->schema([
                        Forms\Components\TextInput::make('client_name')
                            ->label('Nome do Cliente')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('project_id')
                            ->label('Projeto Vinculado')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if (! $state) {
                                    $set('project_name', null);
                                    $set('project_description', null);

                                    return;
                                }

                                $project = Project::find($state);

                                if ($project) {
                                    $set('project_name', $project->name);
                                    $set('project_description', $project->currentSpecification?->ai_specification['objective'] ?? null);
                                }
                            })
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('project_name'),
                        Forms\Components\Hidden::make('project_description'),
                        Forms\Components\Hidden::make('status')
                            ->default('draft'),
                        Forms\Components\Hidden::make('complexity_level')
                            ->default(2),
                        Forms\Components\Hidden::make('urgency_level')
                            ->default(1),
                        Forms\Components\Hidden::make('delivery_days'),
                        Forms\Components\Hidden::make('required_areas')
                            ->default([]),
                        Forms\Components\Hidden::make('backend_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('frontend_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('mobile_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('database_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('devops_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('design_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('testing_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('security_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('pm_hours')
                            ->default(0),
                        Forms\Components\Hidden::make('hourly_rate_backend')
                            ->default(120.00),
                        Forms\Components\Hidden::make('hourly_rate_frontend')
                            ->default(110.00),
                        Forms\Components\Hidden::make('hourly_rate_mobile')
                            ->default(130.00),
                        Forms\Components\Hidden::make('hourly_rate_database')
                            ->default(115.00),
                        Forms\Components\Hidden::make('hourly_rate_devops')
                            ->default(125.00),
                        Forms\Components\Hidden::make('hourly_rate_design')
                            ->default(100.00),
                        Forms\Components\Hidden::make('hourly_rate_testing')
                            ->default(90.00),
                        Forms\Components\Hidden::make('hourly_rate_security')
                            ->default(140.00),
                        Forms\Components\Hidden::make('hourly_rate_pm')
                            ->default(130.00),
                        Forms\Components\Hidden::make('notes'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('project_name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved', 'completed' => 'success',
                        'in_progress' => 'info',
                        'sent' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ProjectQuotation::STATUS_LABELS[$state] ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('complexity_level')
                    ->label('Complexidade')
                    ->formatStateUsing(fn (int $state) => ProjectQuotation::COMPLEXITY_LABELS[$state] ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('urgency_level')
                    ->label('Urgência')
                    ->formatStateUsing(fn (int $state) => ProjectQuotation::URGENCY_LABELS[$state] ?? $state)
                    ->badge()
                    ->color(fn (int $state) => match ($state) {
                        4 => 'danger',
                        3 => 'warning',
                        2 => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_human_hours')
                    ->label('Horas (Humano)')
                    ->suffix('h')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_human_cost')
                    ->label('Custo Humano')
                    ->money('BRL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ai_dev_price')
                    ->label('Preço AI-Dev')
                    ->money('BRL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('savings_percentage')
                    ->label('Economia')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ProjectQuotation::STATUS_LABELS),

                Tables\Filters\SelectFilter::make('urgency_level')
                    ->label('Urgência')
                    ->options(ProjectQuotation::URGENCY_LABELS),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('recalculate')
                    ->label('Calcular')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->action(function (ProjectQuotation $record) {
                        $record->recalculate();
                        $record->save();

                        Notification::make()
                            ->title('Orçamento recalculado')
                            ->body('Custo humano: R$ '.number_format($record->total_human_cost, 2, ',', '.').' | AI-Dev: R$ '.number_format($record->ai_dev_price, 2, ',', '.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Dados do Cliente')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('client_name')
                                    ->label('Cliente'),

                                Infolists\Components\TextEntry::make('project_name')
                                    ->label('Projeto'),

                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => ProjectQuotation::STATUS_LABELS[$state] ?? $state)
                                    ->color(fn (string $state) => match ($state) {
                                        'approved', 'completed' => 'success',
                                        'in_progress' => 'info',
                                        'sent' => 'warning',
                                        'rejected' => 'danger',
                                        default => 'gray',
                                    }),
                            ]),

                        Infolists\Components\TextEntry::make('project_description')
                            ->label('Descrição')
                            ->placeholder('Não informada')
                            ->columnSpanFull(),
                    ]),

                Section::make('Parâmetros')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('complexity_level')
                                    ->label('Complexidade')
                                    ->formatStateUsing(fn (int $state) => ProjectQuotation::COMPLEXITY_LABELS[$state] ?? $state),

                                Infolists\Components\TextEntry::make('urgency_level')
                                    ->label('Urgência')
                                    ->formatStateUsing(fn (int $state) => ProjectQuotation::URGENCY_LABELS[$state] ?? $state)
                                    ->badge(),

                                Infolists\Components\TextEntry::make('delivery_days')
                                    ->label('Prazo')
                                    ->suffix(' dias')
                                    ->placeholder('Não definido'),

                                Infolists\Components\TextEntry::make('team_size')
                                    ->label('Devs por Área')
                                    ->suffix('x'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('urgency_multiplier')
                                    ->label('Multiplicador Urgência')
                                    ->suffix('x'),

                                Infolists\Components\TextEntry::make('complexity_multiplier')
                                    ->label('Multiplicador Complexidade')
                                    ->suffix('x'),
                            ]),
                    ]),

                Section::make('Horas por Área')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('backend_hours')
                                    ->label('Backend')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('frontend_hours')
                                    ->label('Frontend')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('mobile_hours')
                                    ->label('Mobile')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('database_hours')
                                    ->label('Banco de Dados')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('devops_hours')
                                    ->label('DevOps')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('design_hours')
                                    ->label('Design')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('testing_hours')
                                    ->label('QA / Testes')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('security_hours')
                                    ->label('Segurança')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('pm_hours')
                                    ->label('PM')
                                    ->suffix('h'),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Comparativo de Custos')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_human_hours')
                                    ->label('Total de Horas (Humano)')
                                    ->suffix('h'),

                                Infolists\Components\TextEntry::make('total_human_cost')
                                    ->label('Custo Empresa Humana')
                                    ->money('BRL')
                                    ->color('danger'),

                                Infolists\Components\TextEntry::make('ai_dev_price')
                                    ->label('Preço AI-Dev')
                                    ->money('BRL'),

                                Infolists\Components\TextEntry::make('savings_amount')
                                    ->label('Economia para o Cliente')
                                    ->money('BRL'),

                                Infolists\Components\TextEntry::make('savings_percentage')
                                    ->label('% de Economia')
                                    ->suffix('%'),
                            ]),
                    ]),

                Section::make('Custos Reais de Execução AI-Dev')
                    ->description('Atualizado automaticamente durante a execução do projeto.')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('actual_token_cost_usd')
                                    ->label('Custo em Tokens (USD)')
                                    ->prefix('$')
                                    ->placeholder('R$ 0,00'),

                                Infolists\Components\TextEntry::make('actual_infra_cost')
                                    ->label('Custo Infra (BRL)')
                                    ->money('BRL')
                                    ->placeholder('R$ 0,00'),

                                Infolists\Components\TextEntry::make('ai_dev_cost')
                                    ->label('Custo Total Real (BRL)')
                                    ->money('BRL'),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Observações')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('')
                            ->placeholder('Nenhuma observação.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectQuotations::route('/'),
            'create' => Pages\CreateProjectQuotation::route('/create'),
            'view' => Pages\ViewProjectQuotation::route('/{record}'),
            'edit' => Pages\EditProjectQuotation::route('/{record}/edit'),
        ];
    }
}
