<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Usuários';
    protected static ?string $modelLabel = 'Usuário';
    protected static ?string $pluralModelLabel = 'Usuários';
    protected static ?int $navigationSort = 80;
    protected static string|\UnitEnum|null $navigationGroup = 'Administração';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Dados Básicos')
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(255),
                        TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                        TextInput::make('password')->label('Senha')->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create'),
                        Select::make('role')
                            ->label('Perfil de Acesso')
                            ->options(collect(UserRole::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                            ->required()
                            ->native(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Perfil')
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label())
                    ->color(fn (UserRole $state): string => match ($state) {
                        UserRole::Admin => 'danger',
                        UserRole::Developer => 'info',
                        UserRole::QA => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Cadastro')->dateTime()->sortable()->toggleable(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
