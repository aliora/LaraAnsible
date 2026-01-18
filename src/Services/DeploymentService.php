<?php

namespace VisioSoft\LaraAnsible\Services;

use Filament\Notifications\Notification;
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
        bool $notify = true
    ): Deployment {
        \Log::info("DeploymentService::createWithInventoryIds called");
        \Log::info("Inventory IDs: " . json_encode($inventoryIds));
        \Log::info("Task Template ID: {$taskTemplateId}");
        \Log::info("User ID: " . ($userId ?? auth()->id() ?? 'null'));

        $deployment = Deployment::create([
            'task_template_id' => $taskTemplateId,
            'user_id' => $userId ?? auth()->id(),
            'inventory_ids' => $inventoryIds,
            'status' => 'pending',
            'total_hosts' => count($inventoryIds),
        ]);

        \Log::info("Created deployment: id={$deployment->id}, inventory_ids=" . json_encode($deployment->inventory_ids));

        ExecuteAnsibleDeployment::dispatch($deployment);

        if ($notify) {
            Notification::make()
                ->title('Görev Başlatıldı')
                ->body(count($inventoryIds) . ' cihaz için görev kuyruğa alındı.')
                ->success()
                ->send();
        }

        return $deployment;
    }

    /**
     * Repeat a deployment with the same configuration
     */
    public function repeatDeployment(Deployment $deployment, bool $notify = true): Deployment
    {
        return $this->createWithInventoryIds(
            $deployment->inventory_ids ?? [],
            $deployment->task_template_id,
            auth()->id(),
            $notify
        );
    }
}
