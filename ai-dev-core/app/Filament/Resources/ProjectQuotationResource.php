<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectQuotationResource\Pages;
use App\Models\Project;
use App\Models\ProjectQuotation;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectQuotationResource extends Resource
{
    protected static ?string $model = ProjectQuotation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Orçamentos';

    protected static ?string $modelLabel = 'Orçamento';

    protected static ?string $pluralModelLabel = 'Orçamentos';

    protected static ?int $navigationSort = 5;

    protected static string | \UnitEnum | null $navigationGroup = 'Configuracao';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Dados do Cliente')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('client_name')
                                    ->label('Nome do Cliente')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('project_name')
                                    ->label('Nome do Projeto')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Select::make('project_id')
                            ->label('Projeto Vinculado (opcional)')
                            ->relationship('project', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Nenhum — orçamento independente'),

                        Forms\Components\Textarea::make('project_description')
                            ->label('Descrição do Projeto')
                            ->placeholder('Descreva brevemente o que o projeto precisa fazer')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(ProjectQuotation::STATUS_LABELS)
                            ->default('draft')
                            ->required(),
                    ]),

                Section::make('Parâmetros de Complexidade e Urgência')
                    ->description('Estes parâmetros definem os multiplicadores de custo e o tamanho da equipe necessária.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('complexity_level')
                                    ->label('Nível de Complexidade')
                                    ->options(ProjectQuotation::COMPLEXITY_LABELS)
                                    ->default(2)
                                    ->required()
                                    ->helperText('Impacta o multiplicador de custo total.'),

                                Forms\Components\Select::make('urgency_level')
                                    ->label('Urgência de Entrega')
                                    ->options(ProjectQuotation::URGENCY_LABELS)
                                    ->default(1)
                                    ->required()
                                    ->helperText('Urgência aumenta o time necessário e o custo.'),
                            ]),

                        Forms\Components\TextInput::make('delivery_days')
                            ->label('Prazo de Entrega (dias)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Ex: 90'),
                    ]),

                Section::make('Áreas e Horas Estimadas')
                    ->description('Selecione as áreas envolvidas e estime as horas de trabalho para um profissional sênior.')
                    ->schema([
                        Forms\Components\CheckboxList::make('required_areas')
                            ->label('Áreas do Projeto')
                            ->options([
                                'backend'  => 'Backend',
                                'frontend' => 'Frontend',
                                'mobile'   => 'Mobile',
                                'database' => 'Banco de Dados',
                                'devops'   => 'DevOps / Infraestrutura',
                                'design'   => 'Design / UX',
                                'testing'  => 'QA / Testes',
                                'security' => 'Segurança',
                                'pm'       => 'Gerência de Projeto (PM)',
                            ])
                            ->columns(3)
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('backend_hours')
                                    ->label('Horas Backend')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('frontend_hours')
                                    ->label('Horas Frontend')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('mobile_hours')
                                    ->label('Horas Mobile')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('database_hours')
                                    ->label('Horas Banco de Dados')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('devops_hours')
                                    ->label('Horas DevOps')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('design_hours')
                                    ->label('Horas Design')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('testing_hours')
                                    ->label('Horas QA')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('security_hours')
                                    ->label('Horas Segurança')
                                    ->numeric()->minValue(0)->default(0),

                                Forms\Components\TextInput::make('pm_hours')
                                    ->label('Horas PM')
                                    ->numeric()->minValue(0)->default(0),
                            ]),
                    ]),

                Section::make('Taxas Horárias (R$/h)')
                    ->description('Valores padrão baseados no mercado brasileiro (profissional sênior CLT). Ajuste conforme necessário.')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('hourly_rate_backend')
                                    ->label('Backend (R$/h)')
                                    ->numeric()->step(0.01)->default(120.00),

                                Forms\Components\TextInput::make('hourly_rate_frontend')
                                    ->label('Frontend (R$/h)')
                                    ->numeric()->step(0.01)->default(110.00),

                                Forms\Components\TextInput::make('hourly_rate_mobile')
                                    ->label('Mobile (R$/h)')
                                    ->numeric()->step(0.01)->default(130.00),

                                Forms\Components\TextInput::make('hourly_rate_database')
                                    ->label('Banco de Dados (R$/h)')
                                    ->numeric()->step(0.01)->default(115.00),

                                Forms\Components\TextInput::make('hourly_rate_devops')
                                    ->label('DevOps (R$/h)')
                                    ->numeric()->step(0.01)->default(125.00),

                                Forms\Components\TextInput::make('hourly_rate_design')
                                    ->label('Design (R$/h)')
                                    ->numeric()->step(0.01)->default(100.00),

                                Forms\Components\TextInput::make('hourly_rate_testing')
                                    ->label('QA (R$/h)')
                                    ->numeric()->step(0.01)->default(90.00),

                                Forms\Components\TextInput::make('hourly_rate_security')
                                    ->label('Segurança (R$/h)')
                                    ->numeric()->step(0.01)->default(140.00),

                                Forms\Components\TextInput::make('hourly_rate_pm')
                                    ->label('PM (R$/h)')
                                    ->numeric()->step(0.01)->default(130.00),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Observações')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('Notas Adicionais')
                            ->placeholder('Observações internas, condições especiais, etc.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
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
                        'in_progress'           => 'info',
                        'sent'                  => 'warning',
                        'rejected'              => 'danger',
                        default                 => 'gray',
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
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('savings_percentage')
                    ->label('Economia')
                    ->suffix('%')
                    ->color('success')
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
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),

                \Filament\Actions\Action::make('recalculate')
                    ->label('Calcular')
                    ->icon('heroicon-o-calculator')
                    ->color('info')
                    ->action(function (ProjectQuotation $record) {
                        $record->recalculate();
                        $record->save();

                        Notification::make()
                            ->title('Orçamento recalculado')
                            ->body("Custo humano: R$ " . number_format($record->total_human_cost, 2, ',', '.') . " | AI-Dev: R$ " . number_format($record->ai_dev_price, 2, ',', '.'))
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
                                        'in_progress'           => 'info',
                                        'sent'                  => 'warning',
                                        'rejected'              => 'danger',
                                        default                 => 'gray',
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
                                    ->money('BRL')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('savings_amount')
                                    ->label('Economia para o Cliente')
                                    ->money('BRL')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('savings_percentage')
                                    ->label('% de Economia')
                                    ->suffix('%')
                                    ->color('success'),
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
                                    ->money('BRL')
                                    ->color('info'),
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
            'index'  => Pages\ListProjectQuotations::route('/'),
            'create' => Pages\CreateProjectQuotation::route('/create'),
            'view'   => Pages\ViewProjectQuotation::route('/{record}'),
            'edit'   => Pages\EditProjectQuotation::route('/{record}/edit'),
        ];
    }
}
