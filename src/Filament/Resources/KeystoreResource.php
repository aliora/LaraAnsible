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
use VisioSoft\LaraAnsible\Filament\Resources\KeystoreResource\Pages;
use VisioSoft\LaraAnsible\Models\Keystore;

class KeystoreResource extends Resource
{
    protected static ?string $model = Keystore::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Keystore Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('type')
                            ->options([
                                'ssh' => 'SSH Key',
                                'password' => 'Password',
                            ])
                            ->default('ssh')
                            ->required()
                            ->live(),
                    ])
                    ->columns(2),
                Section::make('SSH Key Configuration')
                    ->schema([
                        Forms\Components\Textarea::make('private_key')
                            ->label('Private Key')
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText('Paste your SSH private key here'),
                        Forms\Components\Textarea::make('public_key')
                            ->label('Public Key')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Optional: Paste your SSH public key here'),
                        Forms\Components\TextInput::make('passphrase')
                            ->password()
                            ->revealable()
                            ->helperText('If your key is encrypted with a passphrase'),
                    ])
                    ->visible(fn (Get $get) => $get('type') === 'ssh'),
                Section::make('Password Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get) => $get('type') === 'password'),
                    ])
                    ->visible(fn (Get $get) => $get('type') === 'password'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
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
                    ->label('In Use')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'ssh' => 'SSH Key',
                        'password' => 'Password',
                    ]),
            ])
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListKeystores::route('/'),
            'create' => Pages\CreateKeystore::route('/create'),
            'view' => Pages\ViewKeystore::route('/{record}'),
            'edit' => Pages\EditKeystore::route('/{record}/edit'),
        ];
    }
}
