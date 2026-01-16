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

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Template Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Playbook Configuration')
                    ->schema([
                        Forms\Components\Select::make('playbook_path')
                            ->label('Playbook File')
                            ->options(function () {
                                $directory = config('laraansible.playbook_directory', base_path('ansible'));
                                if (! is_dir($directory)) {
                                    return [];
                                }
                                $files = glob($directory.'/*.yml') ?: [];
                                $files = array_merge($files, glob($directory.'/*.yaml') ?: []);
                                $options = [];
                                foreach ($files as $file) {
                                    $basename = basename($file);
                                    $options[$file] = $basename;
                                }

                                return $options;
                            })
                            ->searchable()
                            ->helperText('Select a playbook file from the configured directory'),
                        Forms\Components\Textarea::make('playbook_content')
                            ->label('Playbook Content (Optional)')
                            ->rows(10)
                            ->columnSpanFull()
                            ->helperText('Optionally paste playbook content here. If both file and content are provided, content takes precedence.'),
                        Forms\Components\KeyValue::make('extra_vars')
                            ->label('Extra Variables')
                            ->keyLabel('Variable Name')
                            ->valueLabel('Value')
                            ->columnSpanFull()
                            ->helperText('Add extra variables to pass to ansible-playbook with --extra-vars'),
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
