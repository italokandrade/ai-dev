<?php

namespace App\Filament\Resources;

use App\Enums\AgentProvider;
use App\Enums\ModuleStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Jobs\ScaffoldProjectJob;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectSpecification;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Projetos';

    protected static ?string $modelLabel = 'Projeto';

    protected static ?string $pluralModelLabel = 'Projetos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Dados do Projeto')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome do Projeto')
                            ->helperText('Nome técnico (sem espaços, lowercase). Ex: portal-italoandrde, meu-saas')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true)
                            ->rules(['regex:/^[a-z0-9\-_]+$/'])
                            ->validationMessages([
                                'regex' => 'Apenas letras minúsculas, números, hífens e underscores.',
                            ]),

                        Forms\Components\TextInput::make('github_repo')
                            ->label('Repositório GitHub')
                            ->placeholder('usuario/repositorio')
                            ->maxLength(255),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('default_provider')
                                    ->label('Provider Padrão')
                                    ->options(AgentProvider::class)
                                    ->default('gemini')
                                    ->required(),

                                Forms\Components\TextInput::make('default_model')
                                    ->label('Modelo Padrão')
                                    ->default('gemini-3.1-flash-lite-preview')
                                    ->required()
                                    ->maxLength(100),
                            ]),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(ProjectStatus::class)
                            ->default('active')
                            ->required(),
                    ])
                    ->columns(1),

                Section::make('Descrição do Sistema')
                    ->description('Descreva o que este sistema deve fazer. A IA irá reescrever sua descrição em uma especificação técnica completa e sugerir os módulos do sistema.')
                    ->schema([
                        Forms\Components\Textarea::make('user_description')
                            ->label('O que este sistema se propõe a fazer?')
                            ->helperText('Escreva livremente — pode ser informal, curta ou detalhada. A IA vai estruturar tudo.')
                            ->placeholder('Ex: Quero um site profissional com portfolio dos meus projetos, blog pra postar artigos técnicos, área de admin pra gerenciar tudo, formulário de contato e que seja bonito com animações modernas. Precisa ser rápido e ter SEO bom.')
                            ->rows(6)
                            ->required()
                            ->columnSpanFull()
                            ->hintAction(
                                \Filament\Actions\Action::make('refineWithAi')
                                    ->label('Refinar com IA')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    ->modalHeading('Refinar Descrição com IA')
                                    ->modalDescription('A IA irá reescrever sua descrição seguindo os padrões do Laravel 13 e TALL Stack.')
                                    ->modalSubmitActionLabel('Usar esta sugestão')
                                    ->form([
                                        Forms\Components\Textarea::make('suggested_description')
                                            ->label('Sugestão da IA')
                                            ->helperText('Você pode editar esta sugestão antes de aceitar.')
                                            ->rows(8)
                                            ->required(),
                                    ])
                                    ->mountUsing(function (\Filament\Schemas\Schema $form, Forms\Components\Textarea $component) {
                                        $state = $component->getState();
                                        
                                        if (blank($state)) {
                                            return;
                                        }

                                        try {
                                            $refined = \App\Ai\Agents\RefineDescriptionAgent::make()
                                                ->prompt("Refine a seguinte descrição de projeto: \n\n" . $state);
                                            
                                            $form->fill([
                                                'suggested_description' => (string) $refined,
                                            ]);
                                        } catch (\Exception $e) {
                                            Notification::make()
                                                ->title('Erro ao refinar com IA')
                                                ->body($e->getMessage())
                                                ->danger()
                                                ->send();
                                        }
                                    })
                                    ->action(function (array $data, Forms\Components\Textarea $component) {
                                        $component->state($data['suggested_description']);
                                        
                                        Notification::make()
                                            ->title('Descrição atualizada')
                                            ->success()
                                            ->send();
                                    })
                            ),
                    ])
                    ->visibleOn('create'),

                Section::make('Senha do Banco de Dados')
                    ->description('Senha para o usuário PostgreSQL que será criado para este projeto.')
                    ->schema([
                        Forms\Components\TextInput::make('db_password')
                            ->label('Senha do Banco')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(6)
                            ->default(fn () => Str::random(16)),
                    ])
                    ->visibleOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('modules_count')
                    ->label('Módulos')
                    ->counts('modules')
                    ->sortable(),

                Tables\Columns\TextColumn::make('overall_progress')
                    ->label('Progresso')
                    ->getStateUsing(fn (Project $record) => $record->overallProgress() . '%')
                    ->color(fn (Project $record) => match (true) {
                        $record->overallProgress() >= 80 => 'success',
                        $record->overallProgress() >= 40 => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks')
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_provider')
                    ->label('Provider')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ProjectStatus::class),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Visão Geral')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('name')
                                    ->label('Projeto'),

                                Infolists\Components\TextEntry::make('status')
                                    ->label('Status')
                                    ->badge(),

                                Infolists\Components\TextEntry::make('overall_progress')
                                    ->label('Progresso Geral')
                                    ->getStateUsing(fn (Project $record) => $record->overallProgress() . '%'),
                            ]),

                        Infolists\Components\TextEntry::make('local_path')
                            ->label('Caminho Local'),

                        Infolists\Components\TextEntry::make('github_repo')
                            ->label('Repositório GitHub')
                            ->placeholder('Não configurado'),
                    ]),

                Section::make('Especificação Técnica')
                    ->schema([
                        Infolists\Components\TextEntry::make('currentSpecification.user_description')
                            ->label('Descrição do Usuário')
                            ->markdown()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('currentSpecification.ai_specification.objective')
                            ->label('Objetivo (gerado pela IA)')
                            ->placeholder('Aguardando geração')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('currentSpecification.ai_specification.core_features')
                            ->label('Funcionalidades Principais')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('currentSpecification.ai_specification.non_functional_requirements')
                            ->label('Requisitos Não-Funcionais')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Roadmap de Módulos')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('modules')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('name')
                                            ->label('Módulo')
                                            ->weight('bold'),

                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Status')
                                            ->badge(),

                                        Infolists\Components\TextEntry::make('progress_percentage')
                                            ->label('Progresso')
                                            ->formatStateUsing(fn ($state) => $state . '%'),

                                        Infolists\Components\TextEntry::make('tasks_count')
                                            ->label('Tasks')
                                            ->getStateUsing(fn (ProjectModule $record) => $record->tasks()->count()),
                                    ]),

                                Infolists\Components\TextEntry::make('description')
                                    ->label('')
                                    ->color('gray'),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
