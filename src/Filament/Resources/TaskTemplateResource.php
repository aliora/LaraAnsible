<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Filament\Resources\TaskTemplateResource\Pages;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class TaskTemplateResource extends Resource
{
    use AuthorizesAnsibleAccess;

    protected static ?string $model = TaskTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.job_templates');
    }

    public static function getModelLabel(): string
    {
        return __('laraansible::laraansible.job_template');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('laraansible::laraansible.template_details'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->extraAttributes(['class' => 'laraansible-full-form'])
                    ->columns(1)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('laraansible::laraansible.template_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('laraansible::laraansible.template_name_placeholder'))
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('playbook_content')
                            ->label(__('laraansible::laraansible.playbook_yaml'))
                            ->required()
                            ->rows(18)
                            ->columnSpanFull()
                            ->placeholder("- hosts: all\n  become: true\n  tasks:\n    - import_tasks: tasks/check_device_type.yml")
                            ->helperText(__('laraansible::laraansible.playbook_yaml_help'))
                            ->extraInputAttributes([
                                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.6; white-space: pre; overflow-x: auto;',
                                'spellcheck' => 'false',
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableHelper::configure($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('playbook_content')
                    ->label(__('laraansible::laraansible.playbook'))
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null),
                Tables\Columns\TextColumn::make('templates_count')
                    ->label(__('laraansible::laraansible.templates'))
                    ->state(fn (TaskTemplate $record): int => count($record->templates ?? [])),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deployments_count')
                    ->counts('deployments')
                    ->label(__('laraansible::laraansible.deployments'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
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
            'index' => Pages\ListTaskTemplates::route('/'),
            'create' => Pages\CreateTaskTemplate::route('/create'),
            'view' => Pages\ViewTaskTemplate::route('/{record}'),
            'edit' => Pages\EditTaskTemplate::route('/{record}/edit'),
        ];
    }
}
