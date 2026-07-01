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
                    ->extraAttributes(['class' => 'laraansible-full-form'])
                    ->columns(1)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Template Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Install Gate')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('playbook_content')
                            ->label('Playbook YAML')
                            ->required()
                            ->rows(18)
                            ->columnSpanFull()
                            ->placeholder("- hosts: all\n  become: true\n  tasks:\n    - import_tasks: tasks/check_device_type.yml")
                            ->helperText('Reference shared tasks with: import_tasks: tasks/<name>')
                            ->extraInputAttributes([
                                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.6; white-space: pre; overflow-x: auto;',
                                'spellcheck' => 'false',
                            ]),
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
                Tables\Columns\TextColumn::make('playbook_content')
                    ->label('Playbook')
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null),
                Tables\Columns\TextColumn::make('templates_count')
                    ->label('Templates')
                    ->state(fn (TaskTemplate $record): int => count($record->templates ?? [])),
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
