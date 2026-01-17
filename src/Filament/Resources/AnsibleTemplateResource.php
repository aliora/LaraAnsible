<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource\Pages;
use VisioSoft\LaraAnsible\Models\AnsibleTemplate;

class AnsibleTemplateResource extends Resource
{
    protected static ?string $model = AnsibleTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?string $navigationLabel = 'File Templates';

    protected static ?string $modelLabel = 'File Template';
    
    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Template Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Filename')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('e.g. nginx.conf.j2'),
                        Forms\Components\Textarea::make('content')
                            ->label('Content')
                            ->rows(20)
                            ->columnSpanFull()
                            ->required()
                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Filename')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnsibleTemplates::route('/'),
            'create' => Pages\CreateAnsibleTemplate::route('/create'),
            'edit' => Pages\EditAnsibleTemplate::route('/{record}/edit'),
        ];
    }
}
