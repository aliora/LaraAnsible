<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
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

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),

                Section::make('Connection Details')
                    ->schema([
                        Forms\Components\TextInput::make('hostname')
                            ->required()
                            ->maxLength(255)
                            ->label('IP / Hostname')
                            ->placeholder('192.168.1.10'),

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

                Section::make('Advanced Configuration')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('script')
                            ->label('Inventory Script / Child Hosts')
                            ->rows(8)
                            ->columnSpanFull()
                            ->placeholder("[gate_server]\n\npi5 ansible_host=100.88.196.89\npi5-3 ansible_host=100.89.209.23\n[gate_server:vars]\nansible_user=root\nansible_ssh_private_key_file=~/.ssh/id_ed25519")
                            ->helperText('Additional hosts can be defined here in INI format. Useful for grouping or adding legacy hosts.'),

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
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
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
            ->headerActions([
                Actions\Action::make('import_from_database')
                    ->label('Import Hosts')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->color('info')
                    ->visible(fn () => $setting && $setting->child_table)
                    ->steps([
                        Step::make(FormSchemaHelper::formatLabel($setting?->parent_table, 'Select Group'))
                            ->icon('heroicon-o-squares-2x2')
                            ->schema(function () use ($setting) {
                                if (! $setting || ! $setting->child_table) {
                                    return [];
                                }

                                $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';
                                $parentOptions = [];

                                if ($setting->parent_table) {
                                    try {
                                        $labelColumn = $setting->parent_label_column ?? 'name';
                                        $parentOptions = DB::table($setting->parent_table)
                                            ->pluck($labelColumn, 'id')
                                            ->toArray();
                                    } catch (\Exception $e) {
                                        $parentOptions = [];
                                    }
                                }

                                return [
                                    Forms\Components\Select::make('parent_id')
                                        ->label(FormSchemaHelper::formatLabel($setting?->parent_table, 'Select Group'))
                                        ->options($parentOptions)
                                        ->live()
                                        ->searchable()
                                        ->required()
                                        ->prefixIcon('heroicon-o-squares-2x2')
                                        ->columnSpanFull(),

                                    Forms\Components\Placeholder::make('stats_summary')
                                        ->label(false)
                                        ->content(function (callable $get) use ($setting, $foreignKey): \Illuminate\Support\HtmlString {
                                            $parentId = $get('parent_id');
                                            if (! $parentId || ! $setting) {
                                                return new \Illuminate\Support\HtmlString('');
                                            }

                                            try {
                                                $total = DB::table($setting->child_table)
                                                    ->where($foreignKey, $parentId)
                                                    ->count();

                                                $childIds = DB::table($setting->child_table)
                                                    ->where($foreignKey, $parentId)
                                                    ->pluck('id');

                                                $imported = Inventory::where('source_type', 'dynamic')
                                                    ->whereIn('dynamic_child_id', $childIds)
                                                    ->count();

                                                $available = max(0, $total - $imported);

                                                return new \Illuminate\Support\HtmlString('
                                                    <div class="flex items-center gap-6 text-sm">
                                                        <div class="flex items-center gap-2">
                                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                            </svg>
                                                            <span class="font-medium text-gray-700 dark:text-gray-300">Total:</span>
                                                            <span class="font-semibold text-blue-600 dark:text-blue-400">'.$total.'</span>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            <span class="font-medium text-gray-700 dark:text-gray-300">Imported:</span>
                                                            <span class="font-semibold text-green-600 dark:text-green-400">'.$imported.'</span>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            <span class="font-medium text-gray-700 dark:text-gray-300">Available:</span>
                                                            <span class="font-semibold text-amber-600 dark:text-amber-400">'.$available.'</span>
                                                        </div>
                                                    </div>
                                                ');
                                            } catch (\Exception $e) {
                                                return new \Illuminate\Support\HtmlString('
                                                    <div class="text-sm text-gray-500">No data available</div>
                                                ');
                                            }
                                        })
                                        ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),

                                    Section::make('Version Distribution')
                                        ->icon('heroicon-o-code-bracket-square')
                                        ->compact()
                                        ->collapsed()
                                        ->schema(function (callable $get) use ($setting, $foreignKey): array {
                                            $parentId = $get('parent_id');
                                            if (! $parentId || ! $setting || ! $setting->version_column) {
                                                return [
                                                    Forms\Components\Placeholder::make('no_version')
                                                        ->label(false)
                                                        ->content('Version tracking not configured'),
                                                ];
                                            }

                                            try {
                                                $versions = DB::table($setting->child_table)
                                                    ->where($foreignKey, $parentId)
                                                    ->whereNotNull($setting->version_column)
                                                    ->select($setting->version_column, DB::raw('COUNT(*) as count'))
                                                    ->groupBy($setting->version_column)
                                                    ->orderByDesc('count')
                                                    ->get();

                                                if ($versions->isEmpty()) {
                                                    return [
                                                        Forms\Components\Placeholder::make('no_versions')
                                                            ->label(false)
                                                            ->content('No version information available'),
                                                    ];
                                                }

                                                return $versions->map(function ($version) use ($setting) {
                                                    $versionValue = $version->{$setting->version_column};

                                                    return Forms\Components\Placeholder::make("version_{$versionValue}")
                                                        ->label($versionValue ?: 'Unknown')
                                                        ->content("{$version->count} hosts")
                                                        ->hint('Version')
                                                        ->hintIcon('heroicon-o-tag');
                                                })->toArray();
                                            } catch (\Exception $e) {
                                                return [
                                                    Forms\Components\Placeholder::make('error')
                                                        ->label(false)
                                                        ->content('Error loading version data'),
                                                ];
                                            }
                                        })
                                        ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),
                                ];
                            }),

                        Step::make(FormSchemaHelper::formatLabel($setting?->child_table, 'Select Hosts'))
                            ->icon('heroicon-o-server')
                            ->schema(function () use ($setting) {
                                if (! $setting || ! $setting->child_table) {
                                    return [];
                                }

                                $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';

                                return [
                                    Forms\Components\CheckboxList::make('child_ids')
                                        ->label('Select Hosts to Import')
                                        ->options(function (callable $get) use ($setting, $foreignKey) {
                                            $parentId = $get('parent_id');
                                            if (! $parentId) {
                                                return [];
                                            }

                                            try {
                                                $labelColumn = $setting->child_label_column ?? 'name';
                                                $hostnameColumn = $setting->child_hostname_column;
                                                $versionColumn = $setting->version_column;

                                                $children = DB::table($setting->child_table)
                                                    ->where($foreignKey, $parentId)
                                                    ->get();

                                                $existingChildIds = Inventory::where('source_type', 'dynamic')
                                                    ->whereIn('dynamic_child_id', $children->pluck('id'))
                                                    ->pluck('dynamic_child_id')
                                                    ->toArray();

                                                return $children->mapWithKeys(function ($child) use ($labelColumn, $hostnameColumn, $versionColumn, $existingChildIds) {
                                                    $label = $child->{$labelColumn} ?? 'Unknown';
                                                    $hostname = $child->{$hostnameColumn} ?? '';
                                                    $version = $versionColumn && isset($child->{$versionColumn}) ? " | v{$child->{$versionColumn}}" : '';
                                                    $status = in_array($child->id, $existingChildIds) ? ' ✓' : '';

                                                    return [$child->id => "{$label} ({$hostname}){$version}{$status}"];
                                                })->toArray();
                                            } catch (\Exception $e) {
                                                return [];
                                            }
                                        })
                                        ->searchable()
                                        ->bulkToggleable()
                                        ->columns(2)
                                        ->required()
                                        ->columnSpanFull()
                                        ->helperText('Hosts marked with ✓ are already imported and will be skipped'),

                                    Forms\Components\Placeholder::make('info')
                                        ->label(false)
                                        ->content('Select one or more hosts to add to inventory')
                                        ->visible(fn (callable $get): bool => empty($get('parent_id'))),
                                ];
                            }),
                    ])
                    ->modalHeading('Import Hosts from Database')
                    ->modalWidth('5xl')
                    ->modalSubmitActionLabel('Import Selected')
                    ->action(function (array $data) use ($setting) {
                        if (empty($data['child_ids'])) {
                            Notification::make()
                                ->warning()
                                ->title('No hosts selected')
                                ->send();

                            return;
                        }

                        $imported = 0;
                        $skipped = 0;

                        foreach ($data['child_ids'] as $childId) {
                            try {
                                $exists = Inventory::where('source_type', 'dynamic')
                                    ->where('dynamic_child_id', $childId)
                                    ->exists();

                                if ($exists) {
                                    $skipped++;

                                    continue;
                                }

                                $child = DB::table($setting->child_table)->find($childId);
                                if (! $child) {
                                    continue;
                                }

                                $labelColumn = $setting->child_label_column ?? 'name';
                                $hostnameColumn = $setting->child_hostname_column;
                                $portColumn = $setting->child_port_column;
                                $usernameColumn = $setting->child_username_column;

                                Inventory::create([
                                    'name' => $child->{$labelColumn} ?? 'Unnamed',
                                    'hostname' => $child->{$hostnameColumn} ?? null,
                                    'port' => $portColumn && isset($child->{$portColumn}) ? $child->{$portColumn} : ($setting->ssh_port ?? 22),
                                    'username' => $usernameColumn && isset($child->{$usernameColumn}) ? $child->{$usernameColumn} : ($setting->ssh_username ?? 'root'),
                                    'source_type' => 'dynamic',
                                    'dynamic_child_id' => $childId,
                                    'is_active' => true,
                                ]);

                                $imported++;
                            } catch (\Exception $e) {
                                \Log::error('Failed to import inventory: '.$e->getMessage());
                            }
                        }

                        Notification::make()
                            ->success()
                            ->title('Import completed')
                            ->body("{$imported} hosts imported, {$skipped} skipped (already exists)")
                            ->send();
                    }),
            ])
            ->actions([
                Actions\Action::make('quick_run')
                    ->label('Quick Run')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->button()
                    ->modalHeading('Run Task')
                    ->modalDescription(fn (Inventory $record): string => "Run task on '{$record->name}'")
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
                    ->label('Quick Run')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->modalHeading('Bulk Run Task')
                    ->modalDescription(fn (Collection $records): string => $records->count().' hosts selected')
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
