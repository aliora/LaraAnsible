<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleTemplateResource\Pages;
use VisioSoft\LaraAnsible\Helpers\AnsibleImporter;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\AnsibleTemplate;

class AnsibleTemplateResource extends Resource
{
    use AuthorizesAnsibleAccess;

    protected static ?string $model = AnsibleTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.file_templates');
    }

    public static function getModelLabel(): string
    {
        return __('laraansible::laraansible.file_template');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('laraansible::laraansible.template_details'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('laraansible::laraansible.filename'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText(__('laraansible::laraansible.filename_help')),
                        Forms\Components\Select::make('kind')
                            ->label(__('laraansible::laraansible.kind'))
                            ->options([
                                'task' => __('laraansible::laraansible.kind_task'),
                                'template' => __('laraansible::laraansible.kind_template'),
                            ])
                            ->native(false)
                            ->helperText(__('laraansible::laraansible.kind_help')),
                        Forms\Components\Textarea::make('content')
                            ->label(__('laraansible::laraansible.content'))
                            ->rows(20)
                            ->columnSpanFull()
                            ->required()
                            ->extraInputAttributes(['style' => 'font-family: monospace;']),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableHelper::configure($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laraansible::laraansible.filename'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('laraansible::laraansible.kind'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'task' => 'info',
                        'template' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->options([
                        'task' => __('laraansible::laraansible.kind_task'),
                        'template' => __('laraansible::laraansible.kind_template'),
                    ]),
            ])
            ->headerActions([
                Actions\Action::make('import')
                    ->label(__('laraansible::laraansible.import'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalHeading(__('laraansible::laraansible.bulk_import_heading'))
                    ->modalDescription(__('laraansible::laraansible.bulk_import_description'))
                    ->schema([
                        Forms\Components\FileUpload::make('files')
                            ->label(__('laraansible::laraansible.ansible_files'))
                            ->multiple()
                            ->required()
                            ->preserveFilenames()
                            ->storeFiles(true)
                            ->disk('local')
                            ->directory('ansible-imports')
                            ->helperText(__('laraansible::laraansible.ansible_files_help')),
                    ])
                    ->action(function (array $data): void {
                        $counts = AnsibleImporter::import($data['files'] ?? []);

                        Notification::make()
                            ->success()
                            ->title(AnsibleImporter::summarize($counts))
                            ->send();
                    }),
            ])
            ->actions(TableHelper::actionGroup([
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
            'index' => Pages\ListAnsibleTemplates::route('/'),
            'create' => Pages\CreateAnsibleTemplate::route('/create'),
            'edit' => Pages\EditAnsibleTemplate::route('/{record}/edit'),
        ];
    }
}
