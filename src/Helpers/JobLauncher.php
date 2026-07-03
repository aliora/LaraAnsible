<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;
use VisioSoft\LaraAnsible\Services\InventoryImportService;

/**
 * Reusable "Run Job" button for any table (row or bulk action). The modal lets
 * the operator pick a job and fill its inputs; every submitted field is passed
 * to the playbook as --extra-vars. Bind row data via extraFields/defaultVars:
 *
 *   JobLauncher::rowAction(defaultVars: fn (Model $r) => ['host_ip' => $r->ip])
 */
class JobLauncher
{
    public static function rowAction(
        string $name = 'run_job',
        array $extraFields = [],
        ?Closure $defaultVars = null,
    ): Action {
        return Action::make($name)
            ->label(__('laraansible::laraansible.run'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->button()
            ->modalHeading(__('laraansible::laraansible.run_job'))
            ->modalDescription(fn (Model $record): string => __('laraansible::laraansible.run_job_on', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('laraansible::laraansible.run'))
            ->fillForm(fn (Model $record): array => [
                'extra_vars' => $defaultVars ? $defaultVars($record) : [],
            ])
            ->schema(static::schema($extraFields))
            ->action(function (Model $record, array $data, $livewire): void {
                static::launch(
                    $livewire,
                    [$record->getKey()],
                    (int) $data['task_template_id'],
                    static::collectVars($data),
                );
            });
    }

    public static function bulkAction(
        string $name = 'run_job',
        array $extraFields = [],
    ): BulkAction {
        return BulkAction::make($name)
            ->label(__('laraansible::laraansible.run'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading(__('laraansible::laraansible.run_job_on_selected'))
            ->modalSubmitActionLabel(__('laraansible::laraansible.run'))
            ->schema(static::schema($extraFields))
            ->action(function (Collection $records, array $data, $livewire): void {
                static::launch(
                    $livewire,
                    $records->pluck($records->first()->getKeyName())->all(),
                    (int) $data['task_template_id'],
                    static::collectVars($data),
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * "Start New Ansible Job" action for a single device. On open it syncs the
     * device's whole park into one dynamic inventory (create or merge), prefills
     * that inventory, and locks the run to this device via `--limit <alias>`.
     */
    public static function recordAction(string $name = 'run_ansible_task'): Action
    {
        return Action::make($name)
            ->label(__('laraansible::laraansible.run_ansible_task'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading(__('laraansible::laraansible.start_new_ansible_job'))
            ->fillForm(fn (Model $record): array => static::prefillForRecord($record))
            ->schema([
                Forms\Components\Select::make('inventory_ids')
                    ->label(__('laraansible::laraansible.target_hosts'))
                    ->options(fn (): array => Inventory::pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Hidden::make('limit_hosts'),
                ...FormSchemaHelper::jobInputsSchema(),
            ])
            ->action(function (Model $record, array $data, $livewire): void {
                if (blank($record->getAttribute('park_id'))) {
                    Notification::make()
                        ->warning()
                        ->title(__('laraansible::laraansible.device_no_park'))
                        ->send();

                    return;
                }

                static::launch(
                    $livewire,
                    $data['inventory_ids'],
                    (int) $data['task_template_id'],
                    static::collectVars($data),
                    $data['limit_hosts'] ?? null,
                );
            });
    }

    /**
     * Sync the device's park inventory and return the prefill: the park
     * inventory id plus the host alias to bind `--limit` to this one device.
     *
     * @return array{inventory_ids: array<int>, limit_hosts: ?string}
     */
    protected static function prefillForRecord(Model $record): array
    {
        $parkId = $record->getAttribute('park_id');
        if (blank($parkId)) {
            return ['inventory_ids' => [], 'limit_hosts' => null];
        }

        $service = app(InventoryImportService::class);
        $inventory = $service->syncPark($parkId)['inventory'];
        if (! $inventory) {
            return ['inventory_ids' => [], 'limit_hosts' => null];
        }

        $setting = AnsibleSetting::getInstance();
        $deviceIp = filled($setting->child_hostname_column)
            ? $record->getAttribute($setting->child_hostname_column)
            : null;

        $alias = $service->resolveHostAlias($inventory, $deviceIp);

        if ($alias) {
            Notification::make()
                ->success()
                ->title(__('laraansible::laraansible.inventory_ready'))
                ->body(__('laraansible::laraansible.inventory_ready_body', [
                    'park' => $inventory->name,
                    'count' => count($inventory->hosts_entry),
                    'host' => $alias,
                ]))
                ->send();
        } else {
            Notification::make()
                ->warning()
                ->title(__('laraansible::laraansible.device_not_matched'))
                ->body(__('laraansible::laraansible.device_not_matched_body'))
                ->send();
        }

        return [
            'inventory_ids' => [$inventory->id],
            'limit_hosts' => $alias,
        ];
    }

    /**
     * Launch a job, first giving the host component a chance to intercept when
     * another active job already targets the same inventory (see
     * HasInventoryConflictGuard).
     *
     * @param  array<int|string>  $inventoryIds
     * @param  array<string, mixed>  $extraVars
     */
    protected static function launch($livewire, array $inventoryIds, int $taskTemplateId, array $extraVars, ?string $limitHosts = null): void
    {
        if (method_exists($livewire, 'guardInventoryConflict')
            && $livewire->guardInventoryConflict($inventoryIds, $taskTemplateId, $extraVars)) {
            return;
        }

        app(DeploymentService::class)->createWithInventoryIds(
            $inventoryIds,
            $taskTemplateId,
            extraVars: $extraVars,
            limitHosts: $limitHosts,
        );
    }

    protected static function schema(array $extraFields): array
    {
        return array_merge(
            FormSchemaHelper::jobInputsSchema(),
            $extraFields,
        );
    }

    protected static function collectVars(array $data): array
    {
        return FormSchemaHelper::collectInputVars($data);
    }
}
