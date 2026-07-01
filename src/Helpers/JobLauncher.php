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
 * Reusable "Run Job" button.
 *
 * Drop it into any table (row action or bulk action). It opens a modal that lets
 * the operator pick a job, fill in inputs, and launch it — the collected inputs
 * are passed to the playbook as --extra-vars ({{ variable }} in the YAML).
 *
 * Row action:
 *   ->actions([ JobLauncher::rowAction() ])
 *
 * Bind inputs to the row's columns — either as typed fields or as prefilled
 * key/value defaults:
 *   JobLauncher::rowAction(
 *       extraFields: [
 *           Forms\Components\Select::make('backend_choice')
 *               ->label('Backend')
 *               ->options(['1' => 'istay', '2' => 'zoneparkbiz', '3' => 'intetra', '4' => 'demirbank'])
 *               ->required(),
 *       ],
 *       defaultVars: fn (Model $r) => ['host_name' => $r->name, 'host_ip' => $r->ip],
 *   )
 *
 * Every typed field value (except the job select) and every key/value pair is
 * sent as an --extra-var, so a Select named `backend_choice` becomes
 * `--extra-vars '{"backend_choice":"2"}'` automatically.
 */
class JobLauncher
{
    public static function rowAction(
        string $name = 'run_job',
        array $extraFields = [],
        ?Closure $defaultVars = null,
    ): Action {
        return Action::make($name)
            ->label('Run')
            ->icon('heroicon-o-play')
            ->color('success')
            ->button()
            ->modalHeading('Run Job')
            ->modalDescription(fn (Model $record): string => "Run a job on '{$record->name}'")
            ->modalSubmitActionLabel('Run')
            ->fillForm(fn (Model $record): array => [
                'extra_vars' => $defaultVars ? $defaultVars($record) : [],
            ])
            ->schema(static::schema($extraFields))
            ->action(function (Model $record, array $data): void {
                app(DeploymentService::class)->createWithInventoryIds(
                    [$record->getKey()],
                    (int) $data['task_template_id'],
                    extraVars: static::collectVars($data),
                );
            });
    }

    public static function bulkAction(
        string $name = 'run_job',
        array $extraFields = [],
    ): BulkAction {
        return BulkAction::make($name)
            ->label('Run')
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading('Run Job on selected hosts')
            ->modalSubmitActionLabel('Run')
            ->schema(static::schema($extraFields))
            ->action(function (Collection $records, array $data): void {
                app(DeploymentService::class)->createWithInventoryIds(
                    $records->pluck($records->first()->getKeyName())->all(),
                    (int) $data['task_template_id'],
                    extraVars: static::collectVars($data),
                );
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * "Start New Ansible Job" action for a table row or edit-form record.
     *
     * Mirrors the OnGoingTasksPage "New Job" modal (Target Hosts + Task), but
     * prefills Target Hosts with the inventory that matches the record's host —
     * resolving (and, if missing, creating) it from the columns configured in
     * AnsibleSetting.
     */
    public static function recordAction(string $name = 'run_ansible_task'): Action
    {
        return Action::make($name)
            ->label(__('Run Ansible Task'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->modalHeading(__('Start New Ansible Job'))
            ->fillForm(fn (Model $record): array => [
                'inventory_ids' => array_filter([static::resolveInventoryId($record)]),
            ])
            ->schema([
                Forms\Components\Select::make('inventory_ids')
                    ->label(__('Target Hosts'))
                    ->options(fn (): array => Inventory::pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
                ...FormSchemaHelper::jobInputsSchema(),
            ])
            ->action(function (array $data): void {
                app(DeploymentService::class)->createWithInventoryIds(
                    $data['inventory_ids'],
                    (int) $data['task_template_id'],
                    extraVars: static::collectVars($data),
                );
            });
    }

    /**
     * Resolve the inventory id for a record, reading its host/name columns from
     * AnsibleSetting. Creates a single-host inventory on the fly when none maps
     * to the record's IP yet.
     */
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

    /**
     * Job select + caller-supplied fields + the free-form variables editor.
     */
    protected static function schema(array $extraFields): array
    {
        return array_merge(
            FormSchemaHelper::jobInputsSchema(),
            $extraFields,
        );
    }

    /**
     * Fold every submitted field (except the job select) into one variables map.
     * The key/value editor and any typed extra field all become --extra-vars.
     */
    protected static function collectVars(array $data): array
    {
        return FormSchemaHelper::collectInputVars($data);
    }
}
