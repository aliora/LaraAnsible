<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;
use VisioSoft\LaraAnsible\Models\Inventory;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Server Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('hostname')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('192.168.1.100 or server.example.com'),
                        Forms\Components\TextInput::make('port')
                            ->required()
                            ->numeric()
                            ->default(22)
                            ->minValue(1)
                            ->maxValue(65535),
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->maxLength(255)
                            ->default('root'),
                        Forms\Components\Select::make('keystore_id')
                            ->relationship('keystore', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required(),
                                Forms\Components\Select::make('type')
                                    ->options([
                                        'ssh' => 'SSH Key',
                                        'password' => 'Password',
                                    ])
                                    ->required(),
                            ]),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('port')
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('keystore.name')
                    ->label('Keystore')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All servers')
                    ->trueLabel('Active servers')
                    ->falseLabel('Inactive servers'),
            ])
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'view' => Pages\ViewInventory::route('/{record}'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}
