<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource\Pages;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\Keystore;

class KeystoreResource extends Resource
{
    use AuthorizesAnsibleAccess;

    protected static ?string $model = Keystore::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.credentials');
    }

    public static function getModelLabel(): string
    {
        return __('laraansible::laraansible.credential');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('laraansible::laraansible.keystore_details'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('laraansible::laraansible.name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_main')
                            ->label(__('laraansible::laraansible.main_key'))
                            ->helperText(__('laraansible::laraansible.main_key_help'))
                            ->default(fn (): bool => ! Keystore::exists()),
                        Forms\Components\Textarea::make('description')
                            ->label(__('laraansible::laraansible.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('type')
                            ->label(__('laraansible::laraansible.type'))
                            ->options([
                                'ssh' => __('laraansible::laraansible.ssh_key'),
                                'password' => __('laraansible::laraansible.password'),
                            ])
                            ->default('ssh')
                            ->required()
                            ->live(),
                    ])
                    ->columns(2),
                Section::make(__('laraansible::laraansible.ssh_key_configuration'))
                    ->schema([
                        Forms\Components\Textarea::make('private_key')
                            ->label(__('laraansible::laraansible.private_key'))
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText(__('laraansible::laraansible.private_key_help')),
                        Forms\Components\Textarea::make('public_key')
                            ->label(__('laraansible::laraansible.public_key'))
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText(__('laraansible::laraansible.public_key_help')),
                        Forms\Components\TextInput::make('passphrase')
                            ->label(__('laraansible::laraansible.passphrase'))
                            ->password()
                            ->revealable()
                            ->helperText(__('laraansible::laraansible.passphrase_help')),
                    ])
                    ->visible(fn (Get $get) => $get('type') === 'ssh'),
                Section::make(__('laraansible::laraansible.password_configuration'))
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label(__('laraansible::laraansible.password'))
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('type') === 'password'),
                    ])
                    ->visible(fn (Get $get) => $get('type') === 'password'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableHelper::configure($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_main')
                    ->label(__('laraansible::laraansible.main_key'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->colors([
                        'primary' => 'ssh',
                        'success' => 'password',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('inventories_count')
                    ->counts('inventories')
                    ->label(__('laraansible::laraansible.in_use'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'ssh' => __('laraansible::laraansible.ssh_key'),
                        'password' => __('laraansible::laraansible.password'),
                    ]),
            ])
            ->actions(TableHelper::actionGroup([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]))
            ->bulkActions(TableHelper::bulkActions());
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKeystores::route('/'),
            'create' => Pages\CreateKeystore::route('/create'),
            'view' => Pages\ViewKeystore::route('/{record}'),
            'edit' => Pages\EditKeystore::route('/{record}/edit'),
        ];
    }
}
