<?php

namespace VisioSoft\LaraAnsible\Services;

use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use VisioSoft\LaraAnsible\Jobs\ExecuteAnsibleDeployment;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class DeploymentService
{
    public function createWithInventoryIds(
        array $inventoryIds,
        int $taskTemplateId,
        ?int $userId = null,
        bool $notify = true,
        array $extraVars = [],
        ?string $limitHosts = null
    ): Deployment {
        $deployment = Deployment::create([
            'task_template_id' => $taskTemplateId,
            'user_id' => $userId ?? auth()->id(),
            'inventory_ids' => $inventoryIds,
            'target_ip' => $this->resolveTargets($inventoryIds),
            'playbook_name' => TaskTemplate::find($taskTemplateId)?->name,
            'extra_vars' => $extraVars ?: null,
            'limit_hosts' => $limitHosts ?: null,
            'status' => 'pending',
            'total_hosts' => count($inventoryIds),
        ]);

        ExecuteAnsibleDeployment::dispatch($deployment);

        if ($notify) {
            Notification::make()
                ->title(__('laraansible::laraansible.job_started'))
                ->body(__('laraansible::laraansible.job_queued_for_devices', ['count' => count($inventoryIds)]))
                ->success()
                ->send();
        }

        return $deployment;
    }

    /**
     * Deduped, comma-joined list of every resolved target host across the
     * selected inventories. Null if none.
     *
     * @param  array<int|string>  $inventoryIds
     */
    private function resolveTargets(array $inventoryIds): ?string
    {
        $ips = [];

        foreach ($inventoryIds as $id) {
            $inventory = Inventory::find($id);
            if (! $inventory) {
                continue;
            }

            $hosts = Inventory::normalizeHostsEntry($inventory->hosts_entry ?? []);

            if (empty($hosts) && filled($inventory->hostname)) {
                $hosts = [$inventory->hostname];
            }

            foreach ($hosts as $ip) {
                if (filled($ip)) {
                    $ips[] = (string) $ip;
                }
            }
        }

        $ips = array_values(array_unique($ips));

        return empty($ips) ? null : implode(', ', $ips);
    }

    /**
     * Active (pending/running) deployments sharing at least one inventory with
     * the selection. Filtered in PHP: inventory_ids is a JSON array cast and
     * the active set is tiny, so portable JSON-overlap SQL is not worth it.
     *
     * @param  array<int|string>  $inventoryIds
     * @return Collection<int, Deployment>
     */
    public function runningConflicts(array $inventoryIds): Collection
    {
        $ids = array_map('intval', $inventoryIds);

        if ($ids === []) {
            return collect();
        }

        return Deployment::whereIn('status', ['pending', 'running'])
            ->get()
            ->filter(fn (Deployment $d): bool => array_intersect(
                array_map('intval', $d->inventory_ids ?? []),
                $ids
            ) !== []);
    }

    /**
     * Kill every ansible-playbook process of this run (matched on its unique
     * "runs/<id>/playbook.yml" path) and mark the deployment failed.
     */
    public function cancel(Deployment $deployment): void
    {
        $id = (int) $deployment->id;

        $pattern = "runs/{$id}/playbook.yml";
        try {
            Process::run(['pkill', '-f', $pattern]);
        } catch (\Throwable $e) {
            Log::warning("cancel(): pkill failed for deployment {$id}: ".$e->getMessage());
        }

        $deployment->appendLog("\n\n=== Stopped by user ===\n");
        $deployment->update([
            'status' => 'failed',
            'completed_at' => now(),
            'exit_code' => 130,
        ]);
    }
}
