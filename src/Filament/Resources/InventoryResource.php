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
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;
use Illuminate\Database\Eloquent\Collection;

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

                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        $setting = \VisioSoft\LaraAnsible\Models\AnsibleSetting::getInstance();
        
        $parentLabel = FormSchemaHelper::formatLabel($setting?->parent_table, 'Bağlı Olduğu Kaynak');
        $childLabel = FormSchemaHelper::formatLabel($setting?->child_table, 'Cihaz Adı');
        $hostnameLabel = FormSchemaHelper::formatLabel($setting?->child_hostname_column, 'IP/Hostname');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label($childLabel),
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->sortable()
                    ->label($hostnameLabel),
                Tables\Columns\TextColumn::make('parent_name')
                    ->label($parentLabel)
                    ->state(function (Inventory $record) use ($setting) {
                         if ($record->source_type === 'dynamic' && $setting && $setting->parent_table && $record->dynamic_child_id) {
                             $parent = \Illuminate\Support\Facades\DB::table($setting->parent_table)
                                 ->where('id', $record->dynamic_child_id)
                                 ->first();
                             $labelColumn = $setting->parent_label_column ?? 'name';
                             return $parent ? ($parent->{$labelColumn} ?? 'Bilinmiyor') : 'Bulunamadı';
                         }
                         return 'Atanmamış';
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Actions\Action::make('quick_run')
                    ->label('Hızlı Çalıştır')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->button()
                    ->modalHeading('Görev Çalıştır')
                    ->modalDescription(fn (Inventory $record): string => "'{$record->name}' cihazında görev çalıştır")
                    ->form([
                        FormSchemaHelper::taskTemplateSelect(),
                    ])
                    ->action(function (Inventory $record, array $data): void {
                        app(DeploymentService::class)->createWithInventoryIds(
                            [$record->id],
                            $data['task_template_id']
                        );
                    }),
                Actions\EditAction::make()
                    ->button(),
            ])
            ->bulkActions([
                Actions\BulkAction::make('quick_run')
                    ->label('Hızlı Çalıştır')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->modalHeading('Toplu Görev Çalıştır')
                    ->modalDescription(fn (Collection $records): string => $records->count() . ' cihaz seçildi')
                    ->form([
                        FormSchemaHelper::taskTemplateSelect(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        app(DeploymentService::class)->createWithInventoryIds(
                            $records->pluck('id')->toArray(),
                            $data['task_template_id']
                        );
                    })
                    ->deselectRecordsAfterCompletion(),
                Actions\DeleteBulkAction::make(),
            ]);
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
