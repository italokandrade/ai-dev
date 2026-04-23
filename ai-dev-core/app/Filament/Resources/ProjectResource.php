<?php

namespace App\Filament\Resources;

use App\Ai\Agents\RefineDescriptionAgent;
use App\Ai\Agents\RefineFeatureAgent;
use App\Enums\ProjectStatus;
use App\Services\AiRuntimeConfigService;
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
                            ->visible(fn ($livewire) => $livewire->record !== null)
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
                                            ->modalDescription('Sua descrição atual será pré-preenchida. Clique em "Refinar" para reescrevê-la com a IA, ou adicione instruções antes de refinar.')
                                            ->modalSubmitActionLabel('Usar esta sugestão')
                                            ->form([
                                                Forms\Components\Textarea::make('suggested_description')
                                                    ->label('Descrição atual')
                                                    ->helperText('Você pode editar este texto livremente.')
                                                    ->rows(8)
                                                    ->required(),

                                                Forms\Components\TextInput::make('refinement_query')
                                                    ->label('O que deseja adicionar ou modificar?')
                                                    ->placeholder('Ex: Adicione um módulo de chat, ou mude o tom para mais formal...')
                                                    ->helperText('Opcional. Deixe em branco para apenas reescrever, ou digite instruções e clique no botão ao lado.')
                                                    ->suffixAction(
                                                        Action::make('applyRefinement')
                                                            ->label('Refinar')
                                                            ->icon('heroicon-o-sparkles')
                                                            ->color('primary')
                                                            ->action(function (Set $set, Get $get) {
                                                                $query = $get('refinement_query');
                                                                $currentText = $get('suggested_description');

                                                                try {
                                                                    $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

                                                                    $promptText = blank($query)
                                                                        ? "Reescreva a seguinte descrição de projeto, melhorando a clareza e coesão sem alterar a essência:\n\n".$currentText
                                                                        : "Reescreva a seguinte descrição de projeto ajustando-a conforme solicitado:\n\n".
                                                                            $currentText.
                                                                            "\n\nAjuste solicitado:\n".$query;

                                                                    $refined = (new RefineDescriptionAgent(base_path()))
                                                                        ->prompt(
                                                                            $promptText,
                                                                            provider: $aiConfig['provider'],
                                                                            model: $aiConfig['model'],
                                                                        );

                                                                    $set('suggested_description', (string) $refined);
                                                                    $set('refinement_query', '');

                                                                    Notification::make()
                                                                        ->title('Descrição refinada com sucesso')
                                                                        ->success()
                                                                        ->send();
                                                                } catch (\Exception $e) {
                                                                    Notification::make()
                                                                        ->title('Erro ao refinar com IA')
                                                                        ->body($e->getMessage())
                                                                        ->danger()
                                                                        ->send();
                                                                }
                                                            })
                                                    ),
                                            ])
                                            ->mountUsing(function (Schema $form, Forms\Components\Textarea $component) {
                                                $state = $component->getState();

                                                $form->fill([
                                                    'suggested_description' => $state ?? '',
                                                ]);
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
                            ->visible(fn ($livewire) => $livewire->record !== null)
                            ->schema([
                                \Filament\Schemas\Components\Actions::make([
                                    Action::make('generateBackendFeatures')
                                        ->label('🤖 Gerar Funcionalidades Backend com IA')
                                        ->icon('heroicon-o-sparkles')
                                        ->color('primary')
                                        ->requiresConfirmation()
                                        ->modalHeading('Gerar Funcionalidades Backend')
                                        ->modalDescription('A IA irá analisar os dados do projeto e gerar funcionalidades backend automaticamente. As funcionalidades existentes não serão removidas.')
                                        ->modalSubmitActionLabel('Gerar')
                                        ->action(function ($livewire) {
                                            $project = $livewire->record;

                                            if (!$project) {
                                                Notification::make()
                                                    ->title('Projeto não encontrado')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }

                                            \App\Jobs\GenerateProjectFeaturesJob::dispatch($project, 'backend');

                                            Notification::make()
                                                ->title('Geração iniciada')
                                                ->body('As funcionalidades backend estão sendo geradas em background. Recarregue a página em alguns instantes.')
                                                ->success()
                                                ->send();
                                        }),
                                ])
                                    ->columnSpanFull(),

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
                                            ->rows(3)
                                            ->hintAction(self::getRefineFeatureAction('backend')),
                                    ])
                                    ->columns(1)
                                    ->addActionLabel('Adicionar Funcionalidade Backend')
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('Funcionalidades Frontend')
                            ->visible(fn ($livewire) => $livewire->record !== null)
                            ->schema([
                                \Filament\Schemas\Components\Actions::make([
                                    Action::make('generateFrontendFeatures')
                                        ->label('🤖 Gerar Funcionalidades Frontend com IA')
                                        ->icon('heroicon-o-sparkles')
                                        ->color('primary')
                                        ->requiresConfirmation()
                                        ->modalHeading('Gerar Funcionalidades Frontend')
                                        ->modalDescription('A IA irá analisar os dados do projeto e gerar funcionalidades frontend automaticamente. As funcionalidades existentes não serão removidas.')
                                        ->modalSubmitActionLabel('Gerar')
                                        ->action(function ($livewire) {
                                            $project = $livewire->record;

                                            if (!$project) {
                                                Notification::make()
                                                    ->title('Projeto não encontrado')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }

                                            \App\Jobs\GenerateProjectFeaturesJob::dispatch($project, 'frontend');

                                            Notification::make()
                                                ->title('Geração iniciada')
                                                ->body('As funcionalidades frontend estão sendo geradas em background. Recarregue a página em alguns instantes.')
                                                ->success()
                                                ->send();
                                        }),
                                ])
                                    ->columnSpanFull(),

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
                                            ->rows(3)
                                            ->hintAction(self::getRefineFeatureAction('frontend')),
                                    ])
                                    ->columns(1)
                                    ->addActionLabel('Adicionar Funcionalidade Frontend')
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                            ]),

                        \Filament\Schemas\Components\Tabs\Tab::make('PRD do Projeto')
                            ->schema([
                                Forms\Components\Hidden::make('prd_payload'),
                                Forms\Components\Hidden::make('prd_approved_at'),

                                Forms\Components\Placeholder::make('prd_status')
                                    ->label('Status do PRD')
                                    ->content(function (Get $get) {
                                        $prd = $get('prd_payload');
                                        $approvedAt = $get('prd_approved_at');

                                        if (empty($prd) || (is_array($prd) && empty($prd['modules'] ?? []))) {
                                            return 'Nenhum PRD gerado. Clique em "Gerar PRD do Projeto" para criar.';
                                        }

                                        if (!empty($approvedAt)) {
                                            return '✅ PRD aprovado em ' . \Illuminate\Support\Carbon::parse($approvedAt)->format('d/m/Y H:i');
                                        }

                                        $moduleCount = count($prd['modules'] ?? []);
                                        return "⏳ PRD gerado com {$moduleCount} módulo(s). Aguardando aprovação.";
                                    })
                                    ->columnSpanFull(),

                                Forms\Components\Placeholder::make('prd_preview')
                                    ->label('Resumo do PRD')
                                    ->visible(function (Get $get) {
                                        $prd = $get('prd_payload');
                                        return !empty($prd) && is_array($prd) && !empty($prd['modules'] ?? []);
                                    })
                                    ->content(function (Get $get) {
                                        $prd = $get('prd_payload');
                                        if (empty($prd) || !is_array($prd)) return '';

                                        $lines = [];
                                        $lines[] = '**Título:** ' . ($prd['title'] ?? '—');
                                        $lines[] = '**Objetivo:** ' . ($prd['objective'] ?? '—');
                                        $lines[] = '**Complexidade:** ' . ($prd['estimated_complexity'] ?? '—');
                                        $lines[] = '';
                                        $lines[] = '**Módulos:**';

                                        foreach ($prd['modules'] ?? [] as $mod) {
                                            $lines[] = '- **' . ($mod['name'] ?? '—') . '** (' . ($mod['priority'] ?? '—') . ')';
                                        }

                                        return new \Illuminate\Support\HtmlString(implode('<br>', $lines));
                                    })
                                    ->columnSpanFull(),

                                \Filament\Schemas\Components\Actions::make([
                                    Action::make('generateProjectPrd')
                                        ->label('🤖 Gerar PRD do Projeto')
                                        ->icon('heroicon-o-sparkles')
                                        ->color('primary')
                                        ->visible(function (Get $get) {
                                            $prd = $get('prd_payload');
                                            return empty($prd) || (is_array($prd) && empty($prd['modules'] ?? []));
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Gerar PRD do Projeto')
                                        ->modalDescription('A IA irá analisar todos os dados do projeto (descrição, funcionalidades backend e frontend) e gerar um PRD Master completo. Isso pode levar alguns minutos.')
                                        ->modalSubmitActionLabel('Gerar PRD')
                                        ->action(function ($livewire) {
                                            $project = $livewire->record;

                                            if (!$project) {
                                                Notification::make()
                                                    ->title('Projeto não encontrado')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }

                                            \App\Jobs\GenerateProjectPrdJob::dispatch($project);

                                            Notification::make()
                                                ->title('Geração do PRD iniciada')
                                                ->body('O PRD Master está sendo gerado em background. Recarregue a página em alguns instantes.')
                                                ->success()
                                                ->send();
                                        }),

                                    Action::make('approveProjectPrd')
                                        ->label('✅ Aprovar PRD e Criar Módulos')
                                        ->icon('heroicon-o-check-circle')
                                        ->color('success')
                                        ->visible(function (Get $get) {
                                            $prd = $get('prd_payload');
                                            $approvedAt = $get('prd_approved_at');
                                            return !empty($prd) && is_array($prd) && !empty($prd['modules'] ?? []) && empty($approvedAt);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Aprovar PRD e Criar Módulos')
                                        ->modalDescription('Ao aprovar, o sistema irá criar automaticamente os MÓDULOS de alto nível do projeto com base no PRD gerado. Submódulos serão definidos posteriormente dentro de cada módulo. Esta ação não pode ser desfeita.')
                                        ->modalSubmitActionLabel('Aprovar e Criar')
                                        ->action(function ($livewire) {
                                            $project = $livewire->record;

                                            if (!$project) {
                                                Notification::make()
                                                    ->title('Projeto não encontrado')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }

                                            try {
                                                $project->approvePrd();
                                                $project->createModulesFromPrd();

                                                Notification::make()
                                                    ->title('PRD aprovado — Módulos criados!')
                                                    ->body('Os módulos foram criados. Acesse cada módulo e gere seu PRD individual para definir submódulos ou tasks.')
                                                    ->success()
                                                    ->send();
                                            } catch (\Exception $e) {
                                                Notification::make()
                                                    ->title('Erro ao aprovar PRD')
                                                    ->body($e->getMessage())
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),

                                    Action::make('regenerateProjectPrd')
                                        ->label('🔄 Regenerar PRD')
                                        ->icon('heroicon-o-arrow-path')
                                        ->color('warning')
                                        ->visible(function (Get $get) {
                                            $prd = $get('prd_payload');
                                            return !empty($prd) && is_array($prd) && !empty($prd['modules'] ?? []);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Regenerar PRD')
                                        ->modalDescription('Um novo PRD será gerado, substituindo o atual. Os módulos já criados não serão afetados.')
                                        ->modalSubmitActionLabel('Regenerar')
                                        ->action(function ($livewire) {
                                            $project = $livewire->record;

                                            if (!$project) {
                                                Notification::make()
                                                    ->title('Projeto não encontrado')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }

                                            $project->update([
                                                'prd_payload' => null,
                                                'prd_approved_at' => null,
                                            ]);

                                            \App\Jobs\GenerateProjectPrdJob::dispatch($project);

                                            Notification::make()
                                                ->title('Regeneração do PRD iniciada')
                                                ->body('Um novo PRD está sendo gerado em background. Recarregue a página em alguns instantes.')
                                                ->success()
                                                ->send();
                                        }),

                                    Action::make('viewFullPrd')
                                        ->label('👁️ Ver PRD Completo')
                                        ->icon('heroicon-o-document-text')
                                        ->color('gray')
                                        ->visible(function (Get $get) {
                                            $prd = $get('prd_payload');
                                            return !empty($prd) && is_array($prd) && !empty($prd['modules'] ?? []);
                                        })
                                        ->modalHeading('PRD Completo do Projeto')
                                        ->modalSubmitAction(false)
                                        ->modalCancelActionLabel('Fechar')
                                        ->modalContent(function (Get $get) {
                                            $prd = $get('prd_payload');
                                            return new \Illuminate\Support\HtmlString(
                                                '<pre class="text-xs overflow-auto max-h-96 bg-gray-100 p-4 rounded">' .
                                                json_encode($prd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
                                                '</pre>'
                                            );
                                        }),
                                ])
                                    ->columnSpanFull(),
                            ])
                            ->visible(function ($livewire) {
                                return $livewire->record !== null;
                            }),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function getRefineFeatureAction(string $featureType): Action
    {
        $typeLabel = $featureType === 'backend' ? 'Backend' : 'Frontend';

        return Action::make("refine{$typeLabel}FeatureWithAi")
            ->label('Refinar com IA')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->modalHeading("Refinar Descrição — Funcionalidade {$typeLabel}")
            ->modalDescription('A descrição atual será pré-preenchida. Clique em "Refinar" para reescrevê-la com a IA, ou adicione instruções antes de refinar.')
            ->modalSubmitActionLabel('Usar esta sugestão')
            ->form([
                Forms\Components\Hidden::make('project_name'),
                Forms\Components\Hidden::make('project_description'),
                Forms\Components\Hidden::make('feature_title'),

                Forms\Components\Textarea::make('suggested_description')
                    ->label('Descrição atual da funcionalidade')
                    ->helperText('Você pode editar este texto livremente.')
                    ->rows(6)
                    ->required(),

                Forms\Components\TextInput::make('refinement_query')
                    ->label('O que deseja adicionar ou modificar?')
                    ->placeholder('Ex: Deixe mais técnico, ou foque no impacto para o usuário...')
                    ->helperText('Opcional. Deixe em branco para apenas reescrever, ou digite instruções e clique no botão ao lado.')
                    ->suffixAction(
                        Action::make("apply{$typeLabel}Refinement")
                            ->label('Refinar')
                            ->icon('heroicon-o-sparkles')
                            ->color('primary')
                            ->action(function (Set $set, Get $get) use ($featureType) {
                                $query = $get('refinement_query');
                                $currentText = $get('suggested_description');
                                $projectName = $get('project_name');
                                $projectDescription = $get('project_description');
                                $featureTitle = $get('feature_title');

                                try {
                                    $aiConfig = AiRuntimeConfigService::apply(AiRuntimeConfigService::LEVEL_PREMIUM);

                                    $promptText = blank($query)
                                        ? "Reescreva a descrição da funcionalidade '{$featureTitle}' ({$featureType}), melhorando a clareza e coesão sem alterar a essência:\n\n{$currentText}"
                                        : "Reescreva a descrição da funcionalidade '{$featureTitle}' ({$featureType}) ajustando-a conforme solicitado:\n\n{$currentText}\n\nAjuste solicitado:\n{$query}";

                                    $promptText .= "\n\n---\nCONTEXTO DO PROJETO '{$projectName}':\n{$projectDescription}\n---\n";
                                    $promptText .= "IMPORTANTE: A descrição refinada deve estar alinhada com o propósito geral do projeto acima. NÃO inclua especificações técnicas de frameworks, ferramentas, versões ou arquitetura no texto final.";

                                    $refined = (new RefineFeatureAgent(base_path()))
                                        ->prompt(
                                            $promptText,
                                            provider: $aiConfig['provider'],
                                            model: $aiConfig['model'],
                                        );

                                    $set('suggested_description', (string) $refined);
                                    $set('refinement_query', '');

                                    Notification::make()
                                        ->title('Descrição refinada com sucesso')
                                        ->success()
                                        ->send();
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('Erro ao refinar com IA')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            })
                    ),
            ])
            ->mountUsing(function (Schema $form, Forms\Components\Textarea $component) {
                $state = $component->getState();

                // Obtém o contexto do projeto via record do form
                $project = $form->getRecord();
                $projectName = $project?->name ?? '';
                $projectDescription = $project?->description ?? '';

                // Tenta obter o título da funcionalidade do container pai (repeater item)
                $featureTitle = '';
                try {
                    $container = $component->getContainer();
                    if ($container) {
                        $parentComponent = $container->getParentComponent();
                        if ($parentComponent) {
                            $parentState = $parentComponent->getRawState();
                            $featureTitle = $parentState['title'] ?? '';
                        }
                    }
                } catch (\Exception $e) {
                    $featureTitle = '';
                }

                $form->fill([
                    'project_name' => $projectName,
                    'project_description' => $projectDescription,
                    'feature_title' => $featureTitle,
                    'suggested_description' => $state ?? '',
                ]);
            })
            ->action(function (array $data, Forms\Components\Textarea $component) {
                $component->state($data['suggested_description']);

                Notification::make()
                    ->title('Descrição da funcionalidade atualizada')
                    ->success()
                    ->send();
            });
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
                    ->getStateUsing(fn (Project $record) =>
                        ($record->root_modules_count ?? 0).' / '.($record->sub_modules_count ?? 0)
                    ),

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

                Section::make('Descrição do Projeto')
                    ->schema([
                        Infolists\Components\TextEntry::make('prd_status')
                            ->label('Status do PRD')
                            ->getStateUsing(fn (Project $record) => match (true) {
                                empty($record->prd_payload) => 'Nenhum PRD gerado',
                                $record->isPrdApproved() => '✅ PRD aprovado em '.$record->prd_approved_at->format('d/m/Y H:i'),
                                default => '⏳ Aguardando aprovação do PRD',
                            })
                            ->color(fn (Project $record) => match (true) {
                                $record->isPrdApproved() => 'success',
                                !empty($record->prd_payload) => 'warning',
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
                                            ->icon('heroicon-o-folder')
                                            ->url(fn (ProjectModule $record) => ProjectModuleResource::getUrl('view', ['record' => $record]))
                                            ->openUrlInNewTab(false),

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
                                            ->getStateUsing(fn (ProjectModule $record) => $record->children->count().' submódulos'),
                                    ]),

                                // Submódulos do módulo
                                Infolists\Components\RepeatableEntry::make('children')
                                    ->label('Submódulos')
                                    ->schema([
                                        Grid::make(4)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('name')
                                                    ->label('Submódulo')
                                                    ->icon('heroicon-o-document-text')
                                                    ->url(fn (ProjectModule $record) => ProjectModuleResource::getUrl('view', ['record' => $record]))
                                                    ->openUrlInNewTab(false),

                                                Infolists\Components\TextEntry::make('status')
                                                    ->label('')
                                                    ->badge(),

                                                Infolists\Components\TextEntry::make('progress_percentage')
                                                    ->label('% Concluído')
                                                    ->formatStateUsing(fn ($state) => $state.'%'),

                                                Infolists\Components\TextEntry::make('tasks_count')
                                                    ->label('Tasks')
                                                    ->getStateUsing(fn (ProjectModule $record) => $record->tasks->count().' tasks'),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
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
            ])
            ->columns(1);
    }

    public static function getRelations(): array
    {
        return [
            ProjectResource\RelationManagers\ProjectModulesRelationManager::class,
        ];
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
