<?php

namespace App\Filament\Resources;

use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\ProjectModule;
use App\Models\Task;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Tarefas';

    protected static ?string $modelLabel = 'Tarefa';

    protected static ?string $pluralModelLabel = 'Tarefas';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Vinculação')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projeto')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->default(request()->query('project_id'))
                            ->afterStateUpdated(fn (Set $set) => $set('module_id', null)),

                        Forms\Components\Select::make('module_id')
                            ->label('Módulo')
                            ->options(fn (Get $get) => ProjectModule::query()
                                ->where('project_id', $get('project_id'))
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->default(request()->query('module_id'))
                            ->helperText('Vincule esta task a um módulo do projeto. Deixe vazio para tasks avulsas (hotfix, Sentinela).'),
                    ])
                    ->columns(2),

                Section::make('Definição da Task')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Título')
                            ->placeholder('Ex: Criar Resource de Usuários no Filament v5')
                            ->required()
                            ->maxLength(500)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('prd_objective')
                            ->label('Objetivo (O que precisa ser feito)')
                            ->placeholder('Descreva detalhadamente o que precisa ser implementado. Quanto mais específico, melhor o resultado dos agentes.')
                            ->rows(4)
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\TagsInput::make('prd_acceptance_criteria')
                            ->label('Critérios de Aceite')
                            ->placeholder('Adicione um critério e pressione Enter')
                            ->helperText('Lista de critérios objetivos. O QA Auditor usa esta lista como checklist.')
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\TagsInput::make('prd_constraints')
                            ->label('Restrições Técnicas')
                            ->placeholder('Ex: Usar apenas FormBuilder do Filament v5')
                            ->helperText('O que NÃO fazer ou o que DEVE ser usado. Tratados como regras invioláveis.')
                            ->columnSpanFull(),

                        Forms\Components\CheckboxList::make('prd_knowledge_areas')
                            ->label('Áreas de Conhecimento')
                            ->options([
                                'backend' => 'Backend (Controllers, Models, Services)',
                                'frontend' => 'Frontend (Blade, Livewire, Alpine.js)',
                                'database' => 'Database (Migrations, Seeders, Queries)',
                                'filament' => 'Filament (Resources, Pages, Widgets)',
                                'devops' => 'DevOps (Deploy, CI/CD, Configuração)',
                                'testing' => 'Testing (Pest, Dusk)',
                                'design' => 'Design (Tailwind, Anime.js, UX)',
                            ])
                            ->required()
                            ->columns(3),
                    ]),

                Section::make('Configuração')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('priority')
                                    ->label('Prioridade')
                                    ->options(\App\Enums\Priority::class)
                                    ->default(\App\Enums\Priority::Normal)
                                    ->required(),

                                Forms\Components\Select::make('source')
                                    ->label('Origem')
                                    ->options(TaskSource::class)
                                    ->default('manual')
                                    ->required(),

                                Forms\Components\TextInput::make('max_retries')
                                    ->label('Máx. Retentativas')
                                    ->numeric()
                                    ->default(3)
                                    ->minValue(1)
                                    ->maxValue(10),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('module.name')
                    ->label('Módulo')
                    ->placeholder('Avulsa')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('assigned_agent_id')
                    ->label('Agente')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Retries')
                    ->formatStateUsing(fn (Task $record) => "{$record->retry_count}/{$record->max_retries}")
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->groups([
                Tables\Grouping\Group::make('module.name')
                    ->label('Módulo')
                    ->collapsible(),

                Tables\Grouping\Group::make('project.name')
                    ->label('Projeto')
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(TaskStatus::class)
                    ->multiple(),

                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name'),

                Tables\Filters\SelectFilter::make('module_id')
                    ->label('Módulo')
                    ->relationship('module', 'name'),

                Tables\Filters\SelectFilter::make('source')
                    ->options(TaskSource::class),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            TaskResource\RelationManagers\SubtasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'view' => Pages\ViewTask::route('/{record}'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
