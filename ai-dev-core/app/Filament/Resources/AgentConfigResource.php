<?php

namespace App\Filament\Resources;

use App\Enums\AgentProvider;
use App\Enums\KnowledgeArea;
use App\Filament\Resources\AgentConfigResource\Pages;
use App\Models\AgentConfig;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AgentConfigResource extends Resource
{
    protected static ?string $model = AgentConfig::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Agentes';

    protected static ?string $modelLabel = 'Agente';

    protected static ?string $pluralModelLabel = 'Agentes';

    protected static ?int $navigationSort = 4;

    protected static string | \UnitEnum | null $navigationGroup = 'Configuracao';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('Identificacao')
                    ->schema([
                        Forms\Components\TextInput::make('id')
                            ->label('ID (slug)')
                            ->placeholder('ex: backend-specialist')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9\-]+$/')
                            ->unique(ignoreRecord: true)
                            ->helperText('Identificador unico em slug. Nao pode ser alterado depois.')
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        Forms\Components\TextInput::make('display_name')
                            ->label('Nome de Exibicao')
                            ->placeholder('ex: Backend Specialist')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Modelo de IA')
                    ->schema([
                        Forms\Components\Select::make('provider')
                            ->label('Provider')
                            ->options(AgentProvider::class)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if ($state) {
                                    $provider = AgentProvider::from($state);
                                    $set('model', $provider->defaultModel());
                                }
                            }),

                        Forms\Components\TextInput::make('model')
                            ->label('Modelo')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\TextInput::make('api_key_env_var')
                            ->label('Variavel de Ambiente da API Key')
                            ->placeholder('ex: ANTHROPIC_API_KEY')
                            ->maxLength(100)
                            ->helperText('Nome da variavel .env que contem a API key.'),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('temperature')
                                    ->label('Temperature')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(2)
                                    ->step(0.1)
                                    ->default(0.3),

                                Forms\Components\TextInput::make('max_tokens')
                                    ->label('Max Tokens')
                                    ->numeric()
                                    ->minValue(100)
                                    ->maxValue(200000)
                                    ->default(8192),

                                Forms\Components\TextInput::make('max_parallel_tasks')
                                    ->label('Tasks Paralelas')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(10)
                                    ->default(2),
                            ]),
                    ]),

                Forms\Components\Section::make('Funcao e Conhecimento')
                    ->schema([
                        Forms\Components\Textarea::make('role_description')
                            ->label('Descricao do Papel')
                            ->placeholder('Descreva o que este agente faz, suas responsabilidades e como ele deve atuar.')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),

                        Forms\Components\CheckboxList::make('knowledge_areas')
                            ->label('Areas de Conhecimento')
                            ->options(KnowledgeArea::class)
                            ->columns(3),

                        Forms\Components\Select::make('fallback_agent_id')
                            ->label('Agente de Fallback')
                            ->options(fn (Forms\Get $get) => AgentConfig::query()
                                ->when($get('id'), fn ($q, $id) => $q->where('id', '!=', $id))
                                ->where('is_active', true)
                                ->pluck('display_name', 'id'))
                            ->searchable()
                            ->placeholder('Nenhum')
                            ->helperText('Agente alternativo caso este falhe ou esteja indisponivel.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('display_name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->color(fn (AgentProvider $state) => match ($state) {
                        AgentProvider::Anthropic => 'warning',
                        AgentProvider::Gemini => 'info',
                        AgentProvider::Ollama => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('model')
                    ->label('Modelo')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('assigned_tasks_count')
                    ->label('Tasks')
                    ->counts('assignedTasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('temperature')
                    ->label('Temp.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('max_tokens')
                    ->label('Max Tokens')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fallbackAgent.display_name')
                    ->label('Fallback')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->filters([
                Tables\Filters\SelectFilter::make('provider')
                    ->options(AgentProvider::class),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentConfigs::route('/'),
            'create' => Pages\CreateAgentConfig::route('/create'),
            'view' => Pages\ViewAgentConfig::route('/{record}'),
            'edit' => Pages\EditAgentConfig::route('/{record}/edit'),
        ];
    }
}
