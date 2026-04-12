<?php

namespace App\Filament\Resources\TaskResource\RelationManagers;

use App\Models\Subtask;
use Filament\Infolists;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SubtasksRelationManager extends RelationManager
{
    protected static string $relationship = 'subtasks';

    protected static ?string $title = 'Sub-PRDs (gerados pelo Orchestrator)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('execution_order')
                    ->label('#')
                    ->sortable()
                    ->width(40),

                Tables\Columns\TextColumn::make('title')
                    ->label('Sub-PRD')
                    ->weight('bold')
                    ->limit(60)
                    ->searchable(),

                Tables\Columns\TextColumn::make('assigned_agent')
                    ->label('Agente')
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Retries')
                    ->formatStateUsing(fn (Subtask $record) => "{$record->retry_count}/{$record->max_retries}")
                    ->color(fn (Subtask $record) => $record->retry_count >= $record->max_retries ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('commit_hash')
                    ->label('Commit')
                    ->placeholder('—')
                    ->limit(8)
                    ->fontFamily('mono')
                    ->copyable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Iniciada')
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Concluída')
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('execution_order')
            ->actions([
                Tables\Actions\Action::make('view_detail')
                    ->label('')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Subtask $record) => "Sub-PRD #{$record->execution_order}: {$record->title}")
                    ->infolist(fn (Schema $schema, Subtask $record): Schema => $schema->schema([
                        Section::make('Identificação')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('title')
                                            ->label('Título')
                                            ->columnSpanFull(),

                                        Infolists\Components\TextEntry::make('assigned_agent')
                                            ->label('Agente Responsável')
                                            ->badge()
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('status')
                                            ->label('Status')
                                            ->badge(),

                                        Infolists\Components\TextEntry::make('retry_count')
                                            ->label('Retentativas')
                                            ->formatStateUsing(fn () => "{$record->retry_count}/{$record->max_retries}"),
                                    ]),
                            ]),

                        Section::make('Sub-PRD')
                            ->schema([
                                Infolists\Components\TextEntry::make('sub_prd_payload.objective')
                                    ->label('Objetivo')
                                    ->columnSpanFull(),

                                Infolists\Components\TextEntry::make('sub_prd_payload.acceptance_criteria')
                                    ->label('Critérios de Aceite')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->columnSpanFull(),

                                Infolists\Components\TextEntry::make('sub_prd_payload.constraints')
                                    ->label('Restrições')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->placeholder('Nenhuma')
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Resultado')
                            ->schema([
                                Infolists\Components\TextEntry::make('commit_hash')
                                    ->label('Commit Hash')
                                    ->placeholder('—')
                                    ->copyable()
                                    ->fontFamily('mono'),

                                Infolists\Components\TextEntry::make('files_modified')
                                    ->label('Arquivos Modificados')
                                    ->listWithLineBreaks()
                                    ->placeholder('—')
                                    ->columnSpanFull(),

                                Infolists\Components\TextEntry::make('qa_feedback')
                                    ->label('Feedback do QA Auditor')
                                    ->placeholder('Nenhum feedback registrado.')
                                    ->columnSpanFull(),

                                Infolists\Components\TextEntry::make('result_log')
                                    ->label('Log de Resultado')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                            ])
                            ->collapsible(),
                    ])),
            ])
            ->bulkActions([])
            ->headerActions([]);
    }
}
