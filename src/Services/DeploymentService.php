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
        $deployment = Deployment::create([
            'task_template_id' => $taskTemplateId,
            'user_id' => $userId ?? auth()->id(),
            'inventory_ids' => $inventoryIds,
            'status' => 'pending',
            'total_hosts' => count($inventoryIds),
        ]);

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
}
