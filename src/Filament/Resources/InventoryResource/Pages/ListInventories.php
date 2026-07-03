<?php

namespace VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\InventoryImportService;

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

        return [
            Actions\CreateAction::make(),

            Actions\Action::make('import_from_database')
                ->label(__('laraansible::laraansible.import_hosts'))
                ->icon('heroicon-o-arrow-down-circle')
                ->color('info')
                ->visible(fn () => $setting && $setting->child_table)
                ->form(function () use ($setting) {
                    if (! $setting || ! $setting->child_table) {
                        return [];
                    }

                    return [
                        Forms\Components\Select::make('parent_id')
                            ->label(FormSchemaHelper::formatLabel($setting?->parent_table, __('laraansible::laraansible.select_group')))
                            ->options($setting->parentOptions())
                            ->live()
                            ->searchable()
                            ->required()
                            ->prefixIcon('heroicon-o-squares-2x2')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\Placeholder::make('total')
                                    ->label(__('laraansible::laraansible.total_hosts'))
                                    ->content(function (callable $get) use ($setting) {
                                        $parentId = $get('parent_id');

                                        return $parentId ? $setting->childrenOf($parentId)->count() : '-';
                                    })
                                    ->icon('heroicon-o-server-stack')
                                    ->iconColor('gray'),

                                Forms\Components\Placeholder::make('imported')
                                    ->label(__('laraansible::laraansible.imported'))
                                    ->content(function (callable $get) use ($setting) {
                                        $parentId = $get('parent_id');

                                        return $parentId
                                            ? count($this->getImportedChildIds($setting->childIdsOf($parentId)))
                                            : '-';
                                    })
                                    ->icon('heroicon-o-check-circle')
                                    ->iconColor('success'),

                                Forms\Components\Placeholder::make('available')
                                    ->label(__('laraansible::laraansible.available'))
                                    ->content(function (callable $get) use ($setting) {
                                        $parentId = $get('parent_id');
                                        if (! $parentId) {
                                            return '-';
                                        }

                                        $childIds = $setting->childIdsOf($parentId);
                                        $imported = count($this->getImportedChildIds($childIds));

                                        return max(0, count($childIds) - $imported);
                                    })
                                    ->icon('heroicon-o-plus-circle')
                                    ->iconColor('primary'),
                            ])
                            ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),

                        Section::make(__('laraansible::laraansible.hosts'))
                            ->description(__('laraansible::laraansible.import_hosts_description'))
                            ->schema([
                                Forms\Components\CheckboxList::make('child_ids')
                                    ->hiddenLabel()
                                    ->extraAlpineAttributes([
                                        'class' => 'laraansible-hosts-checkbox-list overflow-x-auto',
                                    ])
                                    ->options(function (callable $get) use ($setting) {
                                        $parentId = $get('parent_id');
                                        if (! $parentId) {
                                            return [];
                                        }

                                        $children = $setting->childrenOf($parentId);
                                        $existingChildIds = $this->getImportedChildIds($children->pluck('id')->all());

                                        return $children
                                            ->mapWithKeys(fn ($child) => [
                                                $child->id => new HtmlString($this->buildHostOptionLabel($child, $setting, $existingChildIds)),
                                            ])
                                            ->toArray();
                                    })
                                    ->bulkToggleable()
                                    ->columns(1)
                                    ->in(fn (): array => $setting->allChildIds())
                                    ->required(),
                            ])
                            ->columnSpanFull()
                            ->visible(fn (callable $get): bool => ! empty($get('parent_id'))),
                    ];
                })
                ->modalHeading(__('laraansible::laraansible.import_hosts'))
                ->modalWidth('6xl')
                ->modalSubmitActionLabel(__('laraansible::laraansible.import_selected'))
                ->action(function (array $data) use ($setting) {
                    if (empty($data['child_ids'])) {
                        Notification::make()
                            ->warning()
                            ->title(__('laraansible::laraansible.no_hosts_selected'))
                            ->send();

                        return;
                    }

                    if (! $setting || ! $setting->child_table) {
                        return;
                    }

                    try {
                        $result = app(InventoryImportService::class)
                            ->syncPark($data['parent_id'] ?? null, $data['child_ids']);
                    } catch (\Exception $e) {
                        Log::error('Failed to import inventory: '.$e->getMessage());

                        Notification::make()
                            ->danger()
                            ->title(__('laraansible::laraansible.import_failed'))
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    if (! $result['inventory']) {
                        Notification::make()
                            ->warning()
                            ->title(__('laraansible::laraansible.no_hosts_found'))
                            ->send();

                        return;
                    }

                    if ($result['imported'] === 0) {
                        Notification::make()
                            ->warning()
                            ->title(__('laraansible::laraansible.no_new_hosts'))
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('laraansible::laraansible.import_completed'))
                        ->body(__('laraansible::laraansible.import_summary', ['imported' => $result['imported'], 'skipped' => $result['skipped']]))
                        ->send();
                }),
        ];
    }

    protected function buildHostOptionLabel(object $child, AnsibleSetting $setting, array $existingChildIds): string
    {
        $labelColumn = $setting->child_label_column ?? 'name';
        $versionColumn = $setting->version_column;

        $label = $child->{$labelColumn} ?? __('laraansible::laraansible.unknown');
        $hostname = $child->{$setting->child_hostname_column} ?? __('laraansible::laraansible.not_available');
        $version = $versionColumn && isset($child->{$versionColumn}) ? $child->{$versionColumn} : __('laraansible::laraansible.not_available');
        $isImported = in_array($child->id, $existingChildIds);

        $mutedIconClass = 'w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0';
        $serverIcon = svg('heroicon-o-server-stack', $mutedIconClass)->toHtml();
        $ipIcon = svg('heroicon-o-globe-alt', $mutedIconClass)->toHtml();
        $versionIcon = svg('heroicon-o-tag', $mutedIconClass)->toHtml();

        $statusIcon = $isImported
            ? svg('heroicon-s-check-circle', 'w-4 h-4 text-success-500 shrink-0')->toHtml()
            : svg('heroicon-o-plus-circle', 'w-4 h-4 text-primary-500 shrink-0')->toHtml();

        $statusLabel = $isImported
            ? __('laraansible::laraansible.imported')
            : __('laraansible::laraansible.available');

        $bgClass = $isImported
            ? 'bg-success-50/50 dark:bg-success-900/10 border-success-200 dark:border-success-800 opacity-75'
            : 'bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-200 dark:border-gray-700';

        return '<div class="flex items-center gap-4 py-2 px-3 rounded-lg border transition duration-150 '.$bgClass.' w-full">'
            .'<div class="flex items-center gap-2 min-w-[150px] font-medium text-gray-900 dark:text-white">'.$serverIcon.' <span class="truncate">'.$label.'</span></div>'
            .'<div class="flex items-center gap-1.5 min-w-[120px] text-sm text-gray-600 dark:text-gray-400">'.$ipIcon.' <span class="truncate">'.$hostname.'</span></div>'
            .'<div class="flex items-center gap-1.5 min-w-[80px] text-sm text-gray-500 dark:text-gray-400">'.$versionIcon.' <span class="truncate">'.$version.'</span></div>'
            .'<div class="ml-auto flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider '.($isImported ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400').'">'.$statusIcon.' <span>'.$statusLabel.'</span></div>'
            .'</div>';
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

        return array_values(array_unique(array_intersect($importedIds, $childIds)));
    }
}
