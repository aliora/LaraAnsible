<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class TaskTemplateResource extends Resource
{
    protected static ?string $model = TaskTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?string $navigationLabel = 'Job Templates';

    protected static ?string $modelLabel = 'Job Template';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Template Details')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Template Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('playbook_content')
                            ->label('Playbook YAML')
                            ->rows(12)
                            ->required()
                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                    ]),
                Section::make('Associated Files')
                    ->icon('heroicon-o-document-duplicate')
                    ->description('Ansible template files (Jinja2)')
                    ->extraAttributes(['style' => 'max-height: 500px; overflow-y: auto;'])
                    ->schema([
                        Forms\Components\Repeater::make('templates')
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Filename')
                                    ->required()
                                    ->placeholder('nginx.conf.j2'),
                                Forms\Components\Textarea::make('content')
                                    ->label('Content')
                                    ->required()
                                    ->rows(3)
                                    ->extraInputAttributes(['style' => 'font-family: monospace;']),
                            ])
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->addActionLabel('Add File'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deployments_count')
                    ->counts('deployments')
                    ->label('Deployments')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
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
            'index' => Pages\ListTaskTemplates::route('/'),
            'create' => Pages\CreateTaskTemplate::route('/create'),
            'view' => Pages\ViewTaskTemplate::route('/{record}'),
            'edit' => Pages\EditTaskTemplate::route('/{record}/edit'),
        ];
    }
}
