<?php

namespace VisioSoft\LaraAnsible\Services;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Process;
use VisioSoft\LaraAnsible\Jobs\ExecuteAnsibleDeployment;
use VisioSoft\LaraAnsible\Models\Deployment;


class DeploymentService
{
    /**
     * Create a deployment from an inventory group and dispatch the job.
     */


    /**
     * Create a deployment with custom inventory IDs.
     */
    public function createWithInventoryIds(
        array $inventoryIds,
        int $taskTemplateId,
        ?int $userId = null,
        bool $notify = true,
        array $extraVars = []
    ): Deployment {
        // Debug: Log input parameters
        \Log::info("DeploymentService::createWithInventoryIds called");
        \Log::info("Inventory IDs: ".json_encode($inventoryIds));
        \Log::info("Task Template ID: {$taskTemplateId}");
        \Log::info("User ID: ".($userId ?? auth()->id() ?? 'null'));

        $deployment = Deployment::create([
            'task_template_id' => $taskTemplateId,
            'user_id' => $userId ?? auth()->id(),
            'inventory_ids' => $inventoryIds,
            'extra_vars' => $extraVars ?: null,
            'status' => 'pending',
            'total_hosts' => count($inventoryIds),
        ]);

        // Debug: Log created deployment
        \Log::info("Created deployment: id={$deployment->id}, inventory_ids=".json_encode($deployment->inventory_ids));

        ExecuteAnsibleDeployment::dispatch($deployment);

        if ($notify) {
            Notification::make()
                ->title('Görev Başlatıldı')
                ->body(count($inventoryIds).' cihaz için görev kuyruğa alındı.')
                ->success()
                ->send();
        }

        return $deployment;
    }

    /**
     * Stop a running/pending deployment: kill its ansible-playbook process(es)
     * on the controller and mark it failed. Matches on the run's unique playbook
     * path, so it also clears duplicate/orphaned processes for the same run.
     */
    public function cancel(Deployment $deployment): void
    {
        $id = (int) $deployment->id;

        // The command line of every ansible process for this run contains
        // ".../runs/<id>/playbook.yml" — kill them all (SIGTERM).
        $pattern = "runs/{$id}/playbook.yml";
        try {
            Process::run(['pkill', '-f', $pattern]);
        } catch (\Throwable $e) {
            \Log::warning("cancel(): pkill failed for deployment {$id}: ".$e->getMessage());
        }

        $deployment->appendLog("\n\n=== Stopped by user ===\n");
        $deployment->update([
            'status' => 'failed',
            'completed_at' => now(),
            'exit_code' => 130,
        ]);
    }
}
