<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        $setting = AnsibleSetting::getInstance();
        $foreignKey = $setting?->child_parent_foreign_key ?? 'parent_id';

        return [
            Actions\CreateAction::make(),

            Actions\Action::make('import_from_database')
                ->label('Import Hosts')
                ->icon('heroicon-o-arrow-down-circle')
                ->color('info')
                ->visible(fn () => $setting && $setting->child_table)
                ->form(function () use ($setting, $foreignKey) {
                    if (! $setting || ! $setting->child_table) {
                        return [];
                    }

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

                        Grid::make(3)
                            ->schema([
                                Forms\Components\Placeholder::make('total')
                                    ->label('Total Hosts')
                                    ->content(function (callable $get) use ($setting, $foreignKey) {
                                        $parentId = $get('parent_id');
                                        if (! $parentId || ! $setting) {
                                            return '-';
                                        }

                                        try {
                                            return DB::table($setting->child_table)
                                                ->where($foreignKey, $parentId)
                                                ->count();
                                        } catch (\Exception $e) {
                                            return '-';
                                        }
                                    })
                                    ->icon('heroicon-o-server-stack')
                                    ->iconColor('gray'),

                                Forms\Components\Placeholder::make('imported')
                                    ->label('Imported')
                                    ->content(function (callable $get) use ($setting, $foreignKey) {
                                        $parentId = $get('parent_id');
                                        if (! $parentId || ! $setting) {
                                            return '-';
                                        }

                                        try {
                                            $childIds = DB::table($setting->child_table)
                                                ->where($foreignKey, $parentId)
                                                ->pluck('id')
                                                ->toArray();

                                            return count($this->getImportedChildIds($childIds));
                                        } catch (\Exception $e) {
                                            return '-';
                                        }
                                    })
                                    ->icon('heroicon-o-check-circle')
                                    ->iconColor('success'),

                                Forms\Components\Placeholder::make('available')
                                    ->label('Available')
                                    ->content(function (callable $get) use ($setting, $foreignKey) {
                                        $parentId = $get('parent_id');
                                        if (! $parentId || ! $setting) {
                                            return '-';
                                        }

                                        try {
                                            $total = DB::table($setting->child_table)
                                                ->where($foreignKey, $parentId)
                                                ->count();

                                            $childIds = DB::table($setting->child_table)
                                                ->where($foreignKey, $parentId)
                                                ->pluck('id')
                                                ->toArray();

                                            $imported = count($this->getImportedChildIds($childIds));

                                            return max(0, $total - $imported);
                                        } catch (\Exception $e) {
                                            return '-';
                                        }
                                    })
                                    ->icon('heroicon-o-plus-circle')
                                    ->iconColor('primary'),
                            ])
                            ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),

                        Section::make('Hosts')
                            ->description('Select hosts to import into inventory')
                            ->schema([
                                Forms\Components\CheckboxList::make('child_ids')
                                    ->hiddenLabel()
                                    ->extraAlpineAttributes([
                                        'class' => 'laraansible-hosts-checkbox-list',
                                    ])
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

                                            $existingChildIds = $this->getImportedChildIds($children->pluck('id')->all());

                                            return $children->mapWithKeys(function ($child) use ($labelColumn, $hostnameColumn, $versionColumn, $existingChildIds) {
                                                $label = $child->{$labelColumn} ?? 'Unknown';
                                                $hostname = $child->{$hostnameColumn} ?? 'N/A';
                                                $version = $versionColumn && isset($child->{$versionColumn}) ? $child->{$versionColumn} : 'N/A';
                                                $isImported = in_array($child->id, $existingChildIds);

                                                // Icons using Heroicons style paths but simpler color scheme
                                                $serverIcon = '<svg class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>';
                                                $ipIcon = '<svg class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>';
                                                $versionIcon = '<svg class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>';

                                                $statusIcon = $isImported
                                                    ? '<svg class="w-4 h-4 text-success-500 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>'
                                                    : '<svg class="w-4 h-4 text-primary-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';

                                                $bgClass = $isImported
                                                    ? 'bg-success-50/50 dark:bg-success-900/10 border-success-200 dark:border-success-800'
                                                    : 'bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-200 dark:border-gray-700';

                                                $opacityClass = $isImported ? 'opacity-75' : '';

                                                $fullLabel = '<div class="flex items-center gap-4 py-2 px-3 rounded-lg border transition duration-150 '.$bgClass.' '.$opacityClass.' w-full">'
                                                    .'<div class="flex items-center gap-2 min-w-[150px] font-medium text-gray-900 dark:text-white">'.$serverIcon.' <span class="truncate">'.$label.'</span></div>'
                                                    .'<div class="flex items-center gap-1.5 min-w-[120px] text-sm text-gray-600 dark:text-gray-400">'.$ipIcon.' <span class="truncate">'.$hostname.'</span></div>'
                                                    .'<div class="flex items-center gap-1.5 min-w-[80px] text-sm text-gray-500 dark:text-gray-400">'.$versionIcon.' <span class="truncate">'.$version.'</span></div>'
                                                    .'<div class="ml-auto flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider '.($isImported ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400').'">'.$statusIcon.' <span>'.($isImported ? 'Imported' : 'Available').'</span></div>'
                                                    .'</div>';

                                                return [$child->id => new HtmlString($fullLabel)];
                                            })->toArray();
                                        } catch (\Exception $e) {
                                            return [];
                                        }
                                    })
                                    ->bulkToggleable()
                                    ->columns(1)
                                    ->in(fn (): array => DB::table($setting->child_table)->pluck('id')->all())
                                    ->required(),
                            ])
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),
                    ];
                })
                ->modalHeading('Import Hosts')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Import Selected')
                ->action(function (array $data) use ($setting) {
                    if (empty($data['child_ids'])) {
                        Notification::make()
                            ->warning()
                            ->title('No hosts selected')
                            ->send();

                        return;
                    }

                    if (! $setting || ! $setting->child_table) {
                        return;
                    }

                    try {
                        $children = DB::table($setting->child_table)
                            ->whereIn('id', $data['child_ids'])
                            ->get();

                        if ($children->isEmpty()) {
                            Notification::make()
                                ->warning()
                                ->title('No hosts found')
                                ->send();

                            return;
                        }

                        $labelColumn = $setting->child_label_column ?? 'name';
                        $hostnameColumn = $setting->child_hostname_column;
                        $portColumn = $setting->child_port_column;
                        $usernameColumn = $setting->child_username_column;

                        $existingChildIds = $this->getImportedChildIds($children->pluck('id')->all());
                        $hostsEntry = [];
                        $importedChildIds = [];
                        $portValues = [];
                        $usernameValues = [];
                        $skipped = 0;

                        foreach ($children as $child) {
                            if (in_array($child->id, $existingChildIds)) {
                                $skipped++;

                                continue;
                            }

                            $hostname = $child->{$hostnameColumn} ?? null;
                            if (! $hostname) {
                                $skipped++;

                                continue;
                            }

                            $label = $child->{$labelColumn} ?? 'host_'.$child->id;
                            $alias = trim((string) $label);
                            if ($alias === '') {
                                $alias = 'host_'.$child->id;
                            }
                            // Keep spaces and other Ansible-compatible chars, only replace special chars
                            $alias = preg_replace('/[^a-zA-Z0-9_\.\- ]/', '_', Inventory::transliterate($alias));

                            $finalAlias = $alias;
                            $suffix = 2;
                            while (array_key_exists($finalAlias, $hostsEntry)) {
                                $finalAlias = $alias.'_'.$suffix;
                                $suffix++;
                            }

                            // Clean hostname - remove any embedded newlines/tabs
                            $cleanHostname = preg_replace('/[\r\n\t]+/', '', $hostname);
                            $hostsEntry[$finalAlias] = $cleanHostname;
                            $importedChildIds[] = $child->id;

                            if ($portColumn && isset($child->{$portColumn})) {
                                $portValues[] = $child->{$portColumn};
                            }
                            if ($usernameColumn && isset($child->{$usernameColumn})) {
                                $usernameValues[] = $child->{$usernameColumn};
                            }
                        }

                        if (empty($hostsEntry)) {
                            Notification::make()
                                ->warning()
                                ->title('No new hosts to import')
                                ->send();

                            return;
                        }

                        $portValues = array_values(array_unique(array_filter($portValues, fn ($value) => $value !== null && $value !== '')));
                        $usernameValues = array_values(array_unique(array_filter($usernameValues, fn ($value) => $value !== null && $value !== '')));

                        $port = $setting->ssh_port ?? 22;
                        if (count($portValues) === 1) {
                            $port = (int) $portValues[0];
                        }

                        $username = $setting->ssh_username ?? 'root';
                        if (count($usernameValues) === 1) {
                            $username = (string) $usernameValues[0];
                        }

                        $inventoryName = 'Imported Hosts';
                        if (! empty($data['parent_id']) && $setting->parent_table) {
                            $parent = DB::table($setting->parent_table)->find($data['parent_id']);
                            if ($parent) {
                                $parentLabelColumn = $setting->parent_label_column ?? 'name';
                                $inventoryName = $parent->{$parentLabelColumn} ?? $inventoryName;
                            }
                        }

                        $inventoryData = [
                            'name' => $inventoryName,
                            'port' => $port,
                            'username' => $username,
                            'source_type' => 'dynamic',
                            'dynamic_child_id' => $importedChildIds[0] ?? null,
                            'dynamic_child_ids' => $importedChildIds,
                            'hosts_entry' => $hostsEntry,
                            'is_active' => true,
                        ];

                        $script = Inventory::buildInventoryScriptFromData($inventoryData);
                        if ($script !== null) {
                            $inventoryData['script'] = $script;
                        }

                        Inventory::create($inventoryData);

                        $imported = count($hostsEntry);
                    } catch (\Exception $e) {
                        \Log::error('Failed to import inventory: '.$e->getMessage());

                        Notification::make()
                            ->danger()
                            ->title('Import failed')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Import completed')
                        ->body("{$imported} hosts imported, {$skipped} skipped")
                        ->send();
                }),
        ];
    }

    protected function getImportedChildIds(array $childIds): array
    {
        $childIds = array_values(array_filter($childIds, fn ($id) => $id !== null && $id !== ''));
        if (empty($childIds)) {
            return [];
        }

        $inventories = Inventory::where('source_type', 'dynamic')
            ->where(function ($query) use ($childIds) {
                $query->whereIn('dynamic_child_id', $childIds)
                    ->orWhereNotNull('dynamic_child_ids');
            })
            ->get(['dynamic_child_id', 'dynamic_child_ids']);

        $importedIds = [];
        foreach ($inventories as $inventory) {
            if (! empty($inventory->dynamic_child_id)) {
                $importedIds[] = $inventory->dynamic_child_id;
            }

            if (is_array($inventory->dynamic_child_ids)) {
                $importedIds = array_merge($importedIds, $inventory->dynamic_child_ids);
            }
        }

        $importedIds = array_values(array_unique(array_intersect($importedIds, $childIds)));

        return $importedIds;
    }
}
