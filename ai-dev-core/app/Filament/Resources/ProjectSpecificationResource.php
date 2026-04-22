<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectSpecificationResource\Pages;
use App\Jobs\GenerateProjectSpecificationJob;
use App\Models\Project;
use App\Models\ProjectSpecification;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectSpecificationResource extends Resource
{
    protected static ?string $model = ProjectSpecification::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Especificações';

    protected static ?string $modelLabel = 'Especificação';

    protected static ?string $pluralModelLabel = 'Especificações';

    // Oculto do menu: acessível apenas via aba na tela do Projeto
    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Nova Especificação')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projeto')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        Forms\Components\Textarea::make('user_description')
                            ->label('Descrição do Sistema')
                            ->helperText('Descreva o que o sistema deve fazer. A IA irá gerar a especificação técnica completa com módulos e submódulos.')
                            ->placeholder('Ex: Quero um sistema de gestão de academias com controle de alunos, mensalidades, planos, aulas e relatórios.')
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projeto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('version')
                    ->label('Versão')
                    ->formatStateUsing(fn ($state) => "v{$state}")
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(fn (ProjectSpecification $record) => match (true) {
                        $record->isApproved() => 'Aprovada',
                        $record->ai_specification !== null => 'Aguardando Aprovação',
                        default => 'Gerando...',
                    })
                    ->badge()
                    ->color(fn (ProjectSpecification $record) => match (true) {
                        $record->isApproved() => 'success',
                        $record->ai_specification !== null => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('ai_specification.system_name')
                    ->label('Sistema')
                    ->placeholder('Aguardando IA')
                    ->searchable(),

                Tables\Columns\TextColumn::make('modules_count')
                    ->label('Módulos')
                    ->getStateUsing(fn (ProjectSpecification $record) => count($record->ai_specification['modules'] ?? []))
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Aprovada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Não aprovada')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Gerada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name'),

                Tables\Filters\Filter::make('approved')
                    ->label('Aprovadas')
                    ->query(fn ($query) => $query->whereNotNull('approved_at')),

                Tables\Filters\Filter::make('pending')
                    ->label('Aguardando Aprovação')
                    ->query(fn ($query) => $query->whereNull('approved_at')->whereNotNull('ai_specification')),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Coluna esquerda: dados gerais + módulos
                \Filament\Schemas\Components\Group::make([
                    Section::make('Visão Geral')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Infolists\Components\TextEntry::make('project.name')
                                        ->label('Projeto')
                                        ->weight('bold'),

                                    Infolists\Components\TextEntry::make('version')
                                        ->label('Versão')
                                        ->formatStateUsing(fn ($state) => "v{$state}")
                                        ->badge()
                                        ->color('gray'),

                                    Infolists\Components\TextEntry::make('approved_at')
                                        ->label('Status')
                                        ->getStateUsing(fn (ProjectSpecification $record) => match (true) {
                                            $record->isApproved() => '✅ Aprovada em ' . $record->approved_at->format('d/m/Y H:i'),
                                            $record->ai_specification !== null => '⏳ Aguardando aprovação',
                                            default => '🔄 Gerando...',
                                        })
                                        ->color(fn (ProjectSpecification $record) => match (true) {
                                            $record->isApproved() => 'success',
                                            $record->ai_specification !== null => 'warning',
                                            default => 'gray',
                                        }),
                                ]),

                            Infolists\Components\TextEntry::make('user_description')
                                ->label('Descrição Original do Usuário')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Módulos e Submódulos Propostos pela IA')
                        ->description('Hierarquia gerada automaticamente. Ao aprovar, estes módulos serão criados no projeto.')
                        ->schema([
                            Infolists\Components\RepeatableEntry::make('ai_specification.modules')
                                ->label('')
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            Infolists\Components\TextEntry::make('name')
                                                ->label('Módulo')
                                                ->weight('bold')
                                                ->icon('heroicon-o-folder'),

                                            Infolists\Components\TextEntry::make('priority')
                                                ->label('Prioridade')
                                                ->badge()
                                                ->color(fn ($state) => match ($state) {
                                                    'high' => 'danger',
                                                    'medium' => 'warning',
                                                    default => 'gray',
                                                }),

                                            Infolists\Components\TextEntry::make('description')
                                                ->label('Descrição'),
                                        ]),

                                    Infolists\Components\RepeatableEntry::make('submodules')
                                        ->label('Submódulos')
                                        ->schema([
                                            Grid::make(3)
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('name')
                                                        ->label('Submódulo')
                                                        ->icon('heroicon-o-document-text'),

                                                    Infolists\Components\TextEntry::make('priority')
                                                        ->label('Prioridade')
                                                        ->badge()
                                                        ->color(fn ($state) => match ($state) {
                                                            'high' => 'danger',
                                                            'medium' => 'warning',
                                                            default => 'gray',
                                                        }),

                                                    Infolists\Components\TextEntry::make('description')
                                                        ->label('Descrição'),
                                                ]),
                                        ])
                                        ->columnSpanFull(),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),
                ])->columnSpan(['default' => 1, 'xl' => 1]),

                // Coluna direita: especificação técnica completa
                \Filament\Schemas\Components\Group::make([
                    Section::make('Especificação Técnica')
                        ->schema([
                            Infolists\Components\TextEntry::make('ai_specification.system_name')
                                ->label('Nome do Sistema')
                                ->weight('bold')
                                ->columnSpanFull(),


                            Infolists\Components\TextEntry::make('ai_specification.target_audience')
                                ->label('Público-Alvo')
                                ->columnSpanFull(),

                            Grid::make(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('ai_specification.estimated_modules')
                                        ->label('Módulos Estimados')
                                        ->badge()
                                        ->color('info'),

                                    Infolists\Components\TextEntry::make('ai_specification.estimated_complexity')
                                        ->label('Complexidade')
                                        ->badge()
                                        ->color(fn ($state) => match ($state) {
                                            'high', 'complex' => 'danger',
                                            'moderate', 'medium' => 'warning',
                                            default => 'success',
                                        }),
                                ]),

                            Infolists\Components\TextEntry::make('ai_specification.core_features')
                                ->label('Funcionalidades Principais')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('ai_specification.non_functional_requirements')
                                ->label('Requisitos Não-Funcionais')
                                ->listWithLineBreaks()
                                ->bulleted()
                                ->columnSpanFull(),
                        ]),

                    Section::make('Stack Técnica')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('ai_specification.technical_stack.backend')
                                        ->label('Backend'),

                                    Infolists\Components\TextEntry::make('ai_specification.technical_stack.frontend')
                                        ->label('Frontend'),

                                    Infolists\Components\TextEntry::make('ai_specification.technical_stack.admin')
                                        ->label('Admin'),

                                    Infolists\Components\TextEntry::make('ai_specification.technical_stack.database')
                                        ->label('Banco de Dados'),
                                ]),
                        ])
                        ->collapsible(),
                ])->columnSpan(['default' => 1, 'xl' => 1]),
            ])
            ->columns(['default' => 1, 'xl' => 2]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjectSpecifications::route('/'),
            'create' => Pages\CreateProjectSpecification::route('/create'),
            'view' => Pages\ViewProjectSpecification::route('/{record}'),
        ];
    }
}
