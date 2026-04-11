<?php

namespace App\Filament\Resources;

use App\Enums\ModuleStatus;
use App\Filament\Resources\ProjectModuleResource\Pages;
use App\Models\Project;
use App\Models\ProjectModule;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectModuleResource extends Resource
{
    protected static ?string $model = ProjectModule::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Módulos';

    protected static ?string $modelLabel = 'Módulo';

    protected static ?string $pluralModelLabel = 'Módulos';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('Dados do Módulo')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projeto')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('name')
                            ->label('Nome do Módulo')
                            ->placeholder('Ex: Blog, Autenticação, Dashboard Admin')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('description')
                            ->label('Descrição')
                            ->placeholder('O que este módulo faz e o que inclui')
                            ->required()
                            ->rows(3),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options(ModuleStatus::class)
                                    ->default('planned')
                                    ->required(),

                                Forms\Components\TextInput::make('priority')
                                    ->label('Prioridade')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(50),

                                Forms\Components\TextInput::make('order')
                                    ->label('Ordem')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),
                            ]),

                        Forms\Components\TextInput::make('estimated_tasks')
                            ->label('Tasks Estimadas')
                            ->numeric()
                            ->placeholder('Estimativa gerada pela IA'),
                    ]),

                Forms\Components\Section::make('Critérios de Aceite')
                    ->description('Lista de critérios objetivos que definem quando este módulo está pronto.')
                    ->schema([
                        Forms\Components\TagsInput::make('acceptance_criteria')
                            ->label('')
                            ->placeholder('Adicione um critério e pressione Enter')
                            ->helperText('Cada critério deve ser mensurável. Ex: "CRUD de posts com paginação funcionando"'),
                    ]),

                Forms\Components\Section::make('Dependências')
                    ->description('Módulos que precisam estar concluídos antes deste.')
                    ->schema([
                        Forms\Components\Select::make('dependencies')
                            ->label('Depende de')
                            ->multiple()
                            ->options(fn (Forms\Get $get) => ProjectModule::query()
                                ->where('project_id', $get('project_id'))
                                ->when($get('id'), fn ($q, $id) => $q->where('id', '!=', $id))
                                ->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order')
                    ->label('#')
                    ->sortable()
                    ->width('50px'),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Módulo')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progresso')
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->color(fn ($state) => match (true) {
                        $state >= 80 => 'success',
                        $state >= 40 => 'info',
                        $state > 0 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_tasks_count')
                    ->label('Concluídas')
                    ->counts('completedTasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('order')
            ->groups([
                Tables\Grouping\Group::make('project.name')
                    ->label('Projeto')
                    ->collapsible(),
            ])
            ->defaultGroup('project.name')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ModuleStatus::class),

                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('recalculate')
                    ->label('Recalcular')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->action(function (ProjectModule $record) {
                        $record->recalculateProgress();

                        Notification::make()
                            ->title('Progresso recalculado')
                            ->body("{$record->name}: {$record->progress_percentage}%")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('create_task')
                    ->label('Nova Task')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(fn (ProjectModule $record) => TaskResource::getUrl('create', [
                        'module_id' => $record->id,
                        'project_id' => $record->project_id,
                    ])),
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
            'index' => Pages\ListProjectModules::route('/'),
            'create' => Pages\CreateProjectModule::route('/create'),
            'edit' => Pages\EditProjectModule::route('/{record}/edit'),
        ];
    }
}
