<?php

namespace App\Filament\Resources;

use App\Ai\Agents\RefineDescriptionAgent;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectQuotation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Projetos';

    protected static ?string $modelLabel = 'Projeto';

    protected static ?string $pluralModelLabel = 'Projetos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Tabs::make('Project Tabs')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('Dados do Projeto')
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

                                Forms\Components\TextInput::make('local_path')
                                    ->label('Caminho Local no Servidor')
                                    ->placeholder('/var/www/html/projetos/nome-do-projeto')
                                    ->helperText('Caminho absoluto onde o projeto será criado/está localizado.')
                                    ->maxLength(500),

                                Forms\Components\Select::make('status')
                                    ->label('Status')
                                    ->options(ProjectStatus::class)
                                    ->default('active')
                                    ->required(),

                                Forms\Components\TextInput::make('db_password')
                                    ->label('Senha do Banco de Dados')
                                    ->helperText('Senha para o usuário PostgreSQL que será criado para este projeto.')
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->minLength(6)
                                    ->default(fn () => Str::random(16))
                                    ->visibleOn('create'),
                            ])
                            ->columns(1),

                        \Filament\Schemas\Components\Tabs\Tab::make('Descrição do Projeto')
                            ->schema([
                                Forms\Components\Textarea::make('description')
                                    ->label('O que este projeto se propõe a fazer?')
                                    ->helperText('Escreva livremente — pode ser informal, curta ou detalhada. A IA vai estruturar tudo.')
                                    ->placeholder('Ex: Quero um site profissional com portfolio dos meus projetos, blog pra postar artigos técnicos, área de admin pra gerenciar tudo, formulário de contato e que seja bonito com animações modernas. Precisa ser rápido e ter SEO bom.')
                                    ->rows(12)
                                    ->required()
                                    ->columnSpanFull()
                                    ->hintAction(
                                        Action::make('refineWithAi')
                                            ->label('Refinar com IA')
                                            ->icon('heroicon-o-sparkles')
                                            ->color('primary')
                                            ->modalHeading('Refinar Descrição com IA')
                                            ->modalDescription('A IA irá reescrever sua descrição seguindo os padrões do Laravel 13 e TALL Stack.')
                                            ->modalSubmitActionLabel('Usar esta sugestão')
                                            ->form([
                                                Forms\Components\Textarea::make('suggested_description')
                                                    ->label('Sugestão da IA')
                                                    ->helperText('Você pode editar esta sugestão livremente.')
                                                    ->rows(8)
                                                    ->required(),

                                                Forms\Components\TextInput::make('refinement_query')
                                                    ->label('O que deseja adicionar ou modificar?')
                                                    ->placeholder('Ex: Adicione um módulo de chat, ou mude o tom para mais formal...')
                                                    ->helperText('Digite suas alterações e clique no botão circular ao lado para atualizar.')
                                                    ->suffixAction(
                                                        Action::make('applyRefinement')
                                                            ->icon('heroicon-o-arrow-path')
                                                            ->action(function (Set $set, Get $get) {
                                                                $query = $get('refinement_query');
                                                                $currentText = $get('suggested_description');

                                                                if (blank($query)) {
                                                                    Notification::make()
                                                                        ->title('Informe o que deseja alterar')
                                                                        ->warning()
                                                                        ->send();

                                                                    return;
                                                                }

                                                                try {
                                                                    $refined = RefineDescriptionAgent::make()
                                                                        ->prompt("Ajuste o seguinte texto de descrição de projeto:\n\n".
                                                                                $currentText.
                                                                                "\n\nInstrução de modificação do usuário:\n".
                                                                                $query);

                                                                    $set('suggested_description', (string) $refined);
                                                                    $set('refinement_query', ''); // Limpa o campo de entrada

                                                                    Notification::make()
                                                                        ->title('Sugestão atualizada')
                                                                        ->success()
                                                                        ->send();
                                                                } catch (\Exception $e) {
                                                                    Notification::make()
                                                                        ->title('Erro ao processar alteração')
                                                                        ->body($e->getMessage())
                                                                        ->danger()
                                                                        ->send();
                                                                }
                                                            })
                                                    ),
                                            ])
                                            ->mountUsing(function (Schema $form, Forms\Components\Textarea $component) {
                                                $state = $component->getState();

                                                if (blank($state)) {
                                                    return;
                                                }

                                                try {
                                                    $refined = RefineDescriptionAgent::make()
                                                        ->prompt("Refine a seguinte descrição de projeto: \n\n".$state);

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
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Funcionalidades Backend')
                            ->schema([
                                Forms\Components\Repeater::make('backendFeatures')
                                    ->relationship()
                                    ->label('')
                                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                        $data['type'] = 'backend';
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Título')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('description')
                                            ->label('Descrição')
                                            ->rows(3),
                                    ])
                                    ->columns(1)
                                    ->addActionLabel('Adicionar Funcionalidade Backend')
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Funcionalidades Frontend')
                            ->schema([
                                Forms\Components\Repeater::make('frontendFeatures')
                                    ->relationship()
                                    ->label('')
                                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                        $data['type'] = 'frontend';
                                        return $data;
                                    })
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Título')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('description')
                                            ->label('Descrição')
                                            ->rows(3),
                                    ])
                                    ->columns(1)
                                    ->addActionLabel('Adicionar Funcionalidade Frontend')
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                            ]),
                    ])
                    ->columnSpanFull(),
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
                    ->label('Módulos / Submódulos')
                    ->getStateUsing(function (Project $record) {
                        $parentCount = $record->modules()->whereNull('parent_id')->count();
                        $subCount = $record->modules()->whereNotNull('parent_id')->count();

                        return "{$parentCount} / {$subCount}";
                    }),

                Tables\Columns\TextColumn::make('overall_progress')
                    ->label('Progresso')
                    ->getStateUsing(fn (Project $record) => $record->overallProgress().'%')
                    ->color(fn (Project $record) => match (true) {
                        $record->overallProgress() >= 80 => 'success',
                        $record->overallProgress() >= 40 => 'info',
                        default => 'gray',
                    }),

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
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Coluna esquerda — dados do projeto e roadmap
                Group::make([
                    Section::make('Dados do Projeto')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Infolists\Components\TextEntry::make('name')
                                        ->label('Nome do Projeto')
                                        ->weight('bold'),

                                    Infolists\Components\TextEntry::make('status')
                                        ->label('Status')
                                        ->badge(),

                                    Infolists\Components\TextEntry::make('overall_progress')
                                        ->label('Progresso Geral')
                                        ->getStateUsing(fn (Project $record) => $record->overallProgress().'%')
                                        ->color(fn (Project $record) => match (true) {
                                            $record->overallProgress() >= 80 => 'success',
                                            $record->overallProgress() >= 40 => 'warning',
                                            default => 'gray',
                                        }),
                                ]),

                            Grid::make(2)
                                ->schema([
                                    Infolists\Components\TextEntry::make('local_path')
                                        ->label('Caminho Local no Servidor')
                                        ->placeholder('Não configurado')
                                        ->copyable(),

                                    Infolists\Components\TextEntry::make('github_repo')
                                        ->label('Repositório GitHub')
                                        ->placeholder('Não configurado')
                                        ->url(fn ($state) => $state ? "https://github.com/{$state}" : null)
                                        ->openUrlInNewTab(),
                                ]),
                        ]),

                    Section::make('Estrutura do Projeto (Módulos e Submódulos)')
                        ->description('Hierarquia: Módulos (agrupadores) → Submódulos (executáveis) → Tasks')
                        ->schema([
                            Infolists\Components\RepeatableEntry::make('rootModules')
                                ->label('')
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            Infolists\Components\TextEntry::make('name')
                                                ->label('Módulo')
                                                ->weight('bold')
                                                ->icon('heroicon-o-folder'),

                                            Infolists\Components\TextEntry::make('status')
                                                ->label('Status')
                                                ->badge(),

                                            Infolists\Components\TextEntry::make('progress_percentage')
                                                ->label('Progresso')
                                                ->formatStateUsing(fn ($state) => $state.'%')
                                                ->color(fn ($state) => match (true) {
                                                    $state >= 80 => 'success',
                                                    $state >= 40 => 'warning',
                                                    default => 'gray',
                                                }),

                                            Infolists\Components\TextEntry::make('children_count')
                                                ->label('Submódulos')
                                                ->getStateUsing(fn (ProjectModule $record) => $record->children()->count().' submódulos'),
                                        ]),

                                    // Submódulos do módulo
                                    Infolists\Components\RepeatableEntry::make('children')
                                        ->label('Submódulos')
                                        ->schema([
                                            Grid::make(4)
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('name')
                                                        ->label('Submódulo')
                                                        ->icon('heroicon-o-document-text'),

                                                    Infolists\Components\TextEntry::make('status')
                                                        ->label('')
                                                        ->badge(),

                                                    Infolists\Components\TextEntry::make('progress_percentage')
                                                        ->label('% Concluído')
                                                        ->formatStateUsing(fn ($state) => $state.'%'),

                                                    Infolists\Components\TextEntry::make('tasks_count')
                                                        ->label('Tasks')
                                                        ->getStateUsing(fn (ProjectModule $record) => $record->tasks()->count().' tasks'),
                                                ]),
                                        ])
                                        ->columnSpanFull(),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),
                ])->columnSpan(['default' => 1, 'xl' => 1]),

                // Coluna direita — especificação da IA
                Group::make([
                    Section::make('Descrição do Projeto')
                        ->schema([
                            Infolists\Components\TextEntry::make('currentSpecification.approved_at')
                                ->label('Status da Especificação')
                                ->getStateUsing(fn (Project $record) => match (true) {
                                    $record->currentSpecification === null => 'Nenhuma especificação gerada',
                                    $record->currentSpecification->isApproved() => '✅ Aprovada em '.$record->currentSpecification->approved_at->format('d/m/Y H:i'),
                                    default => '⏳ Aguardando aprovação (v'.$record->currentSpecification->version.')',
                                })
                                ->color(fn (Project $record) => match (true) {
                                    $record->currentSpecification?->isApproved() => 'success',
                                    $record->currentSpecification !== null => 'warning',
                                    default => 'gray',
                                })
                                ->columnSpanFull(),

                            Infolists\Components\TextEntry::make('description')
                                ->label('O que este projeto se propõe a fazer?')
                                ->placeholder('—')
                                ->columnSpanFull(),


                            \Filament\Schemas\Components\Tabs::make('Funcionalidades Principais')
                                ->tabs([
                                    \Filament\Schemas\Components\Tabs\Tab::make('Funcionalidades Backend')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('backendFeatures')
                                                ->label('')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('title')
                                                        ->hiddenLabel()
                                                        ->weight('bold')
                                                        ->bulleted(),
                                                    Infolists\Components\TextEntry::make('description')
                                                        ->hiddenLabel()
                                                        ->color('gray')
                                                        ->visible(fn ($state) => filled($state)),
                                                ])
                                                ->columns(1)
                                                ->grid(1)
                                                ->columnSpanFull(),
                                        ]),
                                    \Filament\Schemas\Components\Tabs\Tab::make('Funcionalidades Frontend')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('frontendFeatures')
                                                ->label('')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('title')
                                                        ->hiddenLabel()
                                                        ->weight('bold')
                                                        ->bulleted(),
                                                    Infolists\Components\TextEntry::make('description')
                                                        ->hiddenLabel()
                                                        ->color('gray')
                                                        ->visible(fn ($state) => filled($state)),
                                                ])
                                                ->columns(1)
                                                ->grid(1)
                                                ->columnSpanFull(),
                                        ]),
                                ])
                                ->columnSpanFull(),


                        ])
                        ->collapsible(),

                    Section::make('Orçamento (gerado pela IA)')
                        ->schema([
                            Infolists\Components\TextEntry::make('activeQuotation.status')
                                ->label('Status')
                                ->getStateUsing(fn (Project $record) => match (true) {
                                    $record->activeQuotation === null => 'Aguardando geração',
                                    default => ProjectQuotation::STATUS_LABELS[$record->activeQuotation->status] ?? $record->activeQuotation->status,
                                })
                                ->badge()
                                ->color(fn (Project $record) => match ($record->activeQuotation?->status) {
                                    'approved', 'completed' => 'success',
                                    'sent', 'in_progress' => 'info',
                                    'draft' => 'warning',
                                    'rejected' => 'danger',
                                    default => 'gray',
                                }),

                            Infolists\Components\TextEntry::make('activeQuotation.total_human_hours')
                                ->label('Horas Humanas')
                                ->getStateUsing(fn (Project $record) => $record->activeQuotation
                                    ? number_format((float) $record->activeQuotation->total_human_hours, 0, ',', '.').'h'
                                    : '—')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('activeQuotation.total_human_cost')
                                ->label('Custo Humano')
                                ->getStateUsing(fn (Project $record) => $record->activeQuotation
                                    ? 'R$ '.number_format((float) $record->activeQuotation->total_human_cost, 2, ',', '.')
                                    : '—')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('activeQuotation.ai_dev_price')
                                ->label('Preço AI-Dev')
                                ->getStateUsing(fn (Project $record) => $record->activeQuotation
                                    ? 'R$ '.number_format((float) $record->activeQuotation->ai_dev_price, 2, ',', '.')
                                    : '—')
                                ->placeholder('—')
                                ->color('success'),

                            Infolists\Components\TextEntry::make('activeQuotation.savings_percentage')
                                ->label('Economia')
                                ->getStateUsing(fn (Project $record) => $record->activeQuotation
                                    ? number_format((float) $record->activeQuotation->savings_percentage, 1, ',', '.').'%'
                                    : '—')
                                ->placeholder('—')
                                ->color('success'),
                        ])
                        ->columns(2)
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
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
