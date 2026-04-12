<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SocialAccountResource\Pages;
use App\Models\SocialAccount;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SocialAccountResource extends Resource
{
    protected static ?string $model = SocialAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationLabel = 'Redes Sociais';

    protected static ?string $modelLabel = 'Conta Social';

    protected static ?string $pluralModelLabel = 'Contas Sociais';

    protected static ?int $navigationSort = 6;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuracao';

    /** Campos de credencial exigidos por plataforma */
    private static array $credentialFields = [
        'facebook' => ['app_id', 'app_secret', 'page_id', 'access_token'],
        'instagram' => ['app_id', 'app_secret', 'access_token'],
        'twitter' => ['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'],
        'linkedin' => ['client_id', 'client_secret', 'access_token'],
        'tiktok' => ['app_id', 'app_secret', 'access_token'],
        'youtube' => ['client_id', 'client_secret', 'access_token', 'refresh_token'],
        'pinterest' => ['app_id', 'app_secret', 'access_token'],
        'telegram' => ['bot_token', 'channel_id'],
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identificação')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('project_id')
                                    ->label('Projeto')
                                    ->relationship('project', 'name')
                                    ->required()
                                    ->searchable()
                                    ->preload(),

                                Forms\Components\Select::make('platform')
                                    ->label('Plataforma')
                                    ->options(array_combine(
                                        SocialAccount::platforms(),
                                        array_map('ucfirst', SocialAccount::platforms())
                                    ))
                                    ->required()
                                    ->live(),

                                Forms\Components\TextInput::make('account_name')
                                    ->label('Nome da Conta')
                                    ->placeholder('Ex: Fan Page AndradeItalo')
                                    ->required()
                                    ->maxLength(100),
                            ]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Conta ativa')
                            ->default(true)
                            ->inline(false),
                    ]),

                Section::make('Credenciais de Acesso')
                    ->description('Tokens e chaves API da plataforma. Armazenados criptografados.')
                    ->schema(function (Get $get): array {
                        $platform = $get('platform');

                        if (blank($platform) || ! isset(self::$credentialFields[$platform])) {
                            return [
                                Forms\Components\Placeholder::make('select_platform')
                                    ->label('')
                                    ->content('Selecione a plataforma acima para ver os campos de credencial.'),
                            ];
                        }

                        $fields = [];
                        foreach (self::$credentialFields[$platform] as $key) {
                            $label = match ($key) {
                                'app_id' => 'App ID',
                                'app_secret' => 'App Secret',
                                'page_id' => 'Page ID',
                                'access_token' => 'Access Token',
                                'access_token_secret' => 'Access Token Secret',
                                'consumer_key' => 'Consumer Key',
                                'consumer_secret' => 'Consumer Secret',
                                'client_id' => 'Client ID',
                                'client_secret' => 'Client Secret',
                                'refresh_token' => 'Refresh Token',
                                'bot_token' => 'Bot Token',
                                'channel_id' => 'Channel ID (ex: @canal ou -100xxxxx)',
                                default => ucwords(str_replace('_', ' ', $key)),
                            };

                            $isSecret = str_contains($key, 'secret') || str_contains($key, 'token');

                            $field = Forms\Components\TextInput::make("credentials.{$key}")
                                ->label($label)
                                ->required();

                            if ($isSecret) {
                                $field = $field->password()->revealable();
                            }

                            $fields[] = $field;
                        }

                        return [Grid::make(2)->schema($fields)];
                    })
                    ->visible(fn (Get $get): bool => filled($get('platform'))),

                Section::make('Controle de Token')
                    ->schema([
                        Forms\Components\DateTimePicker::make('token_expires_at')
                            ->label('Token Expira em')
                            ->placeholder('Indefinido')
                            ->helperText('Preencha se a plataforma emite tokens com prazo de validade (ex: Facebook, LinkedIn).'),
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
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('platform')
                    ->label('Plataforma')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'facebook' => 'info',
                        'instagram' => 'warning',
                        'twitter' => 'gray',
                        'linkedin' => 'info',
                        'tiktok' => 'gray',
                        'youtube' => 'danger',
                        'pinterest' => 'danger',
                        'telegram' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('account_name')
                    ->label('Conta')
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('token_expires_at')
                    ->label('Token Expira')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->color(fn (SocialAccount $record): string => match (true) {
                        $record->token_expires_at === null => 'gray',
                        $record->isTokenExpired() => 'danger',
                        $record->token_expires_at->diffInDays() < 7 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_posted_at')
                    ->label('Último Post')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->groups([
                Tables\Grouping\Group::make('project.name')
                    ->label('Projeto')
                    ->collapsible(),

                Tables\Grouping\Group::make('platform')
                    ->label('Plataforma')
                    ->collapsible(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Projeto')
                    ->relationship('project', 'name'),

                Tables\Filters\SelectFilter::make('platform')
                    ->options(array_combine(
                        SocialAccount::platforms(),
                        array_map('ucfirst', SocialAccount::platforms())
                    )),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativas')
                    ->falseLabel('Inativas'),
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
                Section::make('Identificação')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('project.name')
                                    ->label('Projeto')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('platform')
                                    ->label('Plataforma')
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->badge(),

                                Infolists\Components\TextEntry::make('account_name')
                                    ->label('Nome da Conta'),

                                Infolists\Components\IconEntry::make('is_active')
                                    ->label('Ativa')
                                    ->boolean(),
                            ]),
                    ]),

                Section::make('Credenciais')
                    ->description('Chaves armazenadas criptografadas no banco de dados.')
                    ->schema([
                        Infolists\Components\TextEntry::make('credentials_keys')
                            ->label('Campos configurados')
                            ->getStateUsing(fn (SocialAccount $record): string => implode(', ', array_keys($record->credentials ?? [])))
                            ->placeholder('Nenhuma credencial cadastrada')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('credentials_masked')
                            ->label('Prévia (mascarada)')
                            ->getStateUsing(fn (SocialAccount $record): string => collect($record->credentials ?? [])
                                ->map(fn ($v, $k) => "{$k}: ".str_repeat('•', min(strlen((string) $v), 8)))
                                ->implode(' | '))
                            ->placeholder('—')
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Controle de Token')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('token_expires_at')
                                    ->label('Token Expira em')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('Sem expiração definida')
                                    ->color(fn (SocialAccount $record): string => match (true) {
                                        $record->token_expires_at === null => 'gray',
                                        $record->isTokenExpired() => 'danger',
                                        $record->token_expires_at->diffInDays() < 7 => 'warning',
                                        default => 'success',
                                    }),

                                Infolists\Components\TextEntry::make('last_posted_at')
                                    ->label('Último Post')
                                    ->dateTime('d/m/Y H:i')
                                    ->placeholder('Nunca publicou'),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Cadastrada em')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialAccounts::route('/'),
            'create' => Pages\CreateSocialAccount::route('/create'),
            'view' => Pages\ViewSocialAccount::route('/{record}'),
            'edit' => Pages\EditSocialAccount::route('/{record}/edit'),
        ];
    }
}
