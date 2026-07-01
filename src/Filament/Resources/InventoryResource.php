<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Helpers\JobLauncher;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $modelLabel = 'Host';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('General Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Friendly Name')
                            ->placeholder('e.g. Web Server 1'),

                        Forms\Components\Select::make('park_id')
                            ->label('Assigned Park')
                            ->relationship('park', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select a park...'),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),

                Section::make('Connection Details')
                    ->schema([

                        Forms\Components\TextInput::make('port')
                            ->numeric()
                            ->default(22)
                            ->label('SSH Port'),

                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->default(function () {
                                return \VisioSoft\LaraAnsible\Models\AnsibleSetting::getInstance()->ssh_username ?? 'root';
                            })
                            ->label('SSH Username'),

                        Forms\Components\Select::make('keystore_id')
                            ->label('SSH Key')
                            ->relationship('keystore', 'name')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required(),
                                Forms\Components\Textarea::make('private_key')
                                    ->required()
                                    ->label('Private Key (PEM format)'),
                            ]),
                    ])
                    ->columnSpan(1),

                Section::make('Host Management')
                    ->schema([
                        Forms\Components\KeyValue::make('hosts_entry')
                            ->label('Host List')
                            ->keyLabel('Host Name / Alias')
                            ->valueLabel('IP / Hostname')
                            ->addActionLabel('Add Host')
                            ->reorderable()
                            ->columnSpanFull()
                            ->helperText('Define hosts here. Name on the left, IP on the right. Multiple valid.'),
                    ])
                    ->columnSpanFull(),

                Section::make('Advanced Configuration')
                    ->collapsed()
                    ->description('Script will be auto-generated when you save the form based on your Host List and Connection Details.')
                    ->schema([
                        Forms\Components\Textarea::make('script')
                            ->label('Inventory Script / Child Hosts')
                            ->rows(8)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated()
                            ->placeholder("[gate_server]\n\npi5 ansible_host=100.88.196.89\npi5-3 ansible_host=100.89.209.23\n[gate_server:vars]\nansible_user=root\nansible_ssh_private_key_file=~/.ssh/id_ed25519")
                            ->helperText('This field is auto-generated when you save. It will be created from your Host List and Connection Details above.'),

                        Forms\Components\KeyValue::make('variables')
                            ->label('Inventory Variables')
                            ->keyLabel('Variable Name')
                            ->valueLabel('Value'),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        $setting = AnsibleSetting::getInstance();

        $parentLabel = FormSchemaHelper::formatLabel($setting?->parent_table, 'Group/Parent');
        $childLabel = FormSchemaHelper::formatLabel($setting?->child_table, 'Host Name');
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
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->label('SSH User'),
                Tables\Columns\TextColumn::make('parent_name')
                    ->label($parentLabel)
                    ->state(function (Inventory $record) use ($setting) {
                        if ($setting && $setting->parent_table === 'parks' && $record->park_id) {
                            try {
                                $labelColumn = $setting->parent_label_column ?? 'name';
                                $parent = DB::table($setting->parent_table)->find($record->park_id);

                                return $parent ? ($parent->{$labelColumn} ?? 'Unknown') : 'Not Found';
                            } catch (\Exception $e) {
                                return 'Error';
                            }
                        }

                        if ($record->source_type === 'dynamic' && $setting && $setting->parent_table && $record->dynamic_child_id) {
                            $parentTable = $setting->parent_table;
                            $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';

                            try {
                                $child = DB::table($setting->child_table)->find($record->dynamic_child_id);
                                if ($child && isset($child->{$foreignKey})) {
                                    $parent = DB::table($parentTable)->find($child->{$foreignKey});
                                    $labelColumn = $setting->parent_label_column ?? 'name';

                                    return $parent ? ($parent->{$labelColumn} ?? 'Unknown') : 'Not Found';
                                }
                            } catch (\Exception $e) {
                                return 'Error';
                            }
                        }

                        return 'Manual';
                    }),
                Tables\Columns\TextColumn::make('host_count')
                    ->label('Hosts')
                    ->state(fn (Inventory $record): int => count($record->hosts_entry)),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('parent_group')
                    ->label('Parent Group')
                    ->options(function () use ($setting) {
                        if (! $setting || ! $setting->parent_table) {
                            return [];
                        }

                        try {
                            $labelColumn = $setting->parent_label_column ?? 'name';

                            return DB::table($setting->parent_table)
                                ->pluck($labelColumn, 'id')
                                ->toArray();
                        } catch (\Exception $e) {
                            return [];
                        }
                    })
                    ->query(function ($query, $data) use ($setting) {
                        if (! $data['value'] || ! $setting) {
                            return $query;
                        }

                        $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';
                        $childIds = DB::table($setting->child_table)
                            ->where($foreignKey, $data['value'])
                            ->pluck('id');

                        return $query->whereIn('dynamic_child_id', $childIds);
                    }),
            ])
            ->headerActions([])
            ->actions([
                JobLauncher::rowAction('quick_run'),
                Actions\EditAction::make()
                    ->button(),
            ])
            ->bulkActions([
                JobLauncher::bulkAction('quick_run'),
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
