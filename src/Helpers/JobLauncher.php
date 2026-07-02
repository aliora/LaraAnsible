<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;

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
     * "Start New Ansible Job" action prefilled with the inventory matching the
     * record's host, resolved (or created) from the AnsibleSetting columns.
     */
    public static function recordAction(string $name = 'run_ansible_task'): Action
    {
        return Action::make($name)
            ->label(__('laraansible::laraansible.run_ansible_task'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading(__('laraansible::laraansible.start_new_ansible_job'))
            ->fillForm(fn (Model $record): array => [
                'inventory_ids' => array_filter([static::resolveInventoryId($record)]),
            ])
            ->schema([
                Forms\Components\Select::make('inventory_ids')
                    ->label(__('laraansible::laraansible.target_hosts'))
                    ->options(fn (): array => Inventory::pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
                ...FormSchemaHelper::jobInputsSchema(),
            ])
            ->action(function (array $data, $livewire): void {
                static::launch(
                    $livewire,
                    $data['inventory_ids'],
                    (int) $data['task_template_id'],
                    static::collectVars($data),
                );
            });
    }

    /**
     * Launch a job, first giving the host component a chance to intercept when
     * another active job already targets the same inventory (see
     * HasInventoryConflictGuard).
     *
     * @param  array<int|string>  $inventoryIds
     * @param  array<string, mixed>  $extraVars
     */
    protected static function launch($livewire, array $inventoryIds, int $taskTemplateId, array $extraVars): void
    {
        if (method_exists($livewire, 'guardInventoryConflict')
            && $livewire->guardInventoryConflict($inventoryIds, $taskTemplateId, $extraVars)) {
            return;
        }

        app(DeploymentService::class)->createWithInventoryIds(
            $inventoryIds,
            $taskTemplateId,
            extraVars: $extraVars,
        );
    }

    protected static function resolveInventoryId(Model $record): ?int
    {
        $setting = AnsibleSetting::getInstance();

        $ip = filled($setting->child_hostname_column) ? $record->getAttribute($setting->child_hostname_column) : null;
        $name = filled($setting->child_label_column) ? $record->getAttribute($setting->child_label_column) : null;

        if (blank($ip)) {
            return null;
        }

        return Inventory::firstOrCreate(
            ['hostname' => $ip],
            [
                'name' => $name ?: $ip,
                'port' => (int) ($setting->ssh_port ?: 22),
                'username' => $setting->ssh_username ?: 'root',
                'source_type' => 'dynamic',
                'dynamic_child_id' => $record->getKey(),
                'is_active' => true,
            ],
        )->getKey();
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
