<?php

namespace App\Filament\Resources;

use App\Enums\ModuleStatus;
use App\Filament\Resources\ProjectModuleResource\Pages;
use App\Models\Project;
use App\Models\ProjectModule;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;

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
                Section::make('Dados do Módulo')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projeto')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),

                        Forms\Components\Select::make('parent_id')
                            ->label('Módulo Pai')
                            ->placeholder('Nenhum (Módulo Raiz)')
                            ->options(fn (Get $get, ?ProjectModule $record) => ProjectModule::query()
                                ->where('project_id', $get('project_id'))
                                ->when($record, fn ($q, $rec) => $q->where('id', '!=', $rec->id))
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get) => filled($get('project_id'))),

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

                        Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options(ModuleStatus::class)
                                    ->default('planned')
                                    ->required(),

                                Forms\Components\Select::make('priority')
                                    ->label('Prioridade')
                                    ->options(\App\Enums\Priority::class)
                                    ->default(\App\Enums\Priority::Normal)
                                    ->required(),
                            ]),
                    ]),

                Section::make('Dependências')
                    ->description('Módulos que precisam estar concluídos antes deste.')
                    ->schema([
                        Forms\Components\Select::make('dependencies')
                            ->label('Depende de')
                            ->multiple()
                            ->options(fn (Get $get, ?ProjectModule $record) => ProjectModule::query()
                                ->where('project_id', $get('project_id'))
                                ->where(function ($query) use ($record) {
                                    $query->where('status', ModuleStatus::Completed);
                                    
                                    // Manter as dependências já selecionadas na lista, mesmo que não estejam concluídas
                                    if ($record && !empty($record->dependencies)) {
                                        $query->orWhereIn('id', $record->dependencies);
                                    }
                                })
                                ->when($record, fn ($q, $rec) => $q->where('id', '!=', $rec->id))
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->preload(),
                    ])
                    ->collapsible(),
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Módulo')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ProjectModule $record) => $record->parent?->name),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridade')
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

                Tables\Columns\TextColumn::make('completed_tasks_count')
                    ->label('Concluídas')
                    ->counts('completedTasks')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ModuleStatus::class),

                Tables\Filters\SelectFilter::make('parent_id')
                    ->label('Módulo Pai')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name'),
            ])
            ->actions([
                Action::make('entrar')
                    ->label(fn (ProjectModule $record) => (string) $record->children()->count())
                    ->icon('heroicon-m-folder-open')
                    ->action(fn (ProjectModule $record, Pages\ListProjectModules $livewire) => $livewire->setActiveModule($record->id))
                    ->color('info')
                    ->link()
                    ->visible(fn (ProjectModule $record) => $record->children()->exists()),
                \Filament\Actions\ViewAction::make()->label(''),
                EditAction::make()->label(''),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ProjectModuleResource\RelationManagers\TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectModules::route('/'),
            'create' => Pages\CreateProjectModule::route('/create'),
            'view' => Pages\ViewProjectModule::route('/{record}'),
            'edit' => Pages\EditProjectModule::route('/{record}/edit'),
        ];
    }
}
